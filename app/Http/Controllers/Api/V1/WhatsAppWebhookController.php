<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\SystemSetting;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WhatsApp delivery webhook handler — Meta Cloud API format.
 *
 * The BSP ("The Internet Store") relays Meta's webhook payload verbatim
 * to our /api/v1/webhooks/whatsapp endpoint. We:
 *   1. Optionally verify a configured shared-secret token via the
 *      `X-Webhook-Token` header (set whatsapp_webhook_token in
 *      SystemSetting or .env). If empty we accept all callers — the
 *      worst case is an outsider POSTing a fake "delivered" status,
 *      which only affects an admin's view of an already-sent message.
 *   2. Decompose Meta's "entry → changes → value" structure into one
 *      atomic row per (message_id, status) in temple_whatsapp_webhook_events.
 *      Composite-unique key dedupes BSP retries (we sometimes 200 slowly).
 *   3. For status events that match a `provider_message_id` on
 *      temple_notification_logs, propagate the live state up onto the
 *      log row (delivery_status / delivery_status_at / failure_reason)
 *      so admins reading the Notifications page see the actual
 *      delivery outcome, not just the upstream-API-accepted outcome.
 *
 * Meta payload shapes we handle:
 *   • value.statuses[]  — sent / delivered / read / failed status updates
 *   • value.messages[]  — inbound replies from devotees (logged but not
 *                          yet routed anywhere; future feature)
 *
 * Everything else (template approval updates, account quality, phone
 * number metadata) is logged with event_kind='unknown' or the matching
 * category so support can still see them, but no behaviour is wired.
 *
 * GET requests respond to Meta's verification handshake (some BSPs
 * forward it). The hub.verify_token query param is compared against
 * the configured token; matching tokens echo hub.challenge back.
 */
class WhatsAppWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // CHALLENGE VERIFICATION (POST variant). Some BSPs ("The Internet
        // Store" among them, per the "Failed to verify challenge" UI
        // toast) POST a tiny payload with just a `challenge` field at
        // setup time and expect it echoed back before they'll save the
        // webhook. Detect that BEFORE the auth check + JSON parsing so
        // we never reject our own setup probe.
        $payload = $request->all();
        $challengeResponse = $this->maybeEchoChallenge($request, $payload);
        if ($challengeResponse !== null) {
            return $challengeResponse;
        }

        // 1. Optional shared-secret check. Configurable so the user can
        // tighten this later by setting whatsapp_webhook_token in
        // SystemSetting / .env. Until then we log "auth not verified"
        // and process anyway — getting visibility on failures is the
        // primary goal of wiring this endpoint at all.
        $configuredToken = SystemSetting::getValue(
            'whatsapp_webhook_token',
            config('whatsapp.webhook_token', ''),
        );
        if ($configuredToken) {
            $providedToken = $request->header('X-Webhook-Token', '');
            if (! hash_equals($configuredToken, (string) $providedToken)) {
                Log::warning('WhatsApp webhook: invalid X-Webhook-Token');
                return response()->json(['status' => 'invalid_token'], 401);
            }
        }

        if (! is_array($payload) || empty($payload)) {
            Log::warning('WhatsApp webhook: empty or non-JSON payload');
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        // 1automations.com BSP shape (and similar wrappers — Wati,
        // some AiSensy plans) sends one event per HTTP POST, NOT the
        // Meta entry[].changes[] structure:
        //
        //   { "messaging_channel": "whatsapp",
        //     "message":  { "queue_id": "<bsp-uuid>", "message_status": "sent|delivered|read|failed" },
        //     "response": { "messages": [{ "id": "wamid..." }] }  // on success
        //                   OR { "error": { "code": N, "message": "..." } } } // on failure
        //
        // Detect this shape FIRST and route to a dedicated handler — the
        // Meta entry[]/changes[] code path doesn't recognise the wrapper
        // so every event would otherwise be classified `unknown`.
        if (isset($payload['messaging_channel']) && isset($payload['message'])) {
            return $this->processBspWrappedEvent($payload);
        }

        // Meta wraps everything in entry[].changes[].value{}. Each
        // change carries either `messages` (inbound), `statuses`
        // (delivery updates), or one of the metadata fields.
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries) || empty($entries)) {
            // Some BSPs flatten — try processing the payload itself as
            // a single change-value before declaring it malformed.
            return $this->processValue($payload);
        }

        $totals = ['processed' => 0, 'duplicates' => 0, 'errors' => 0];

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                if (! is_array($value)) {
                    continue;
                }
                try {
                    $result = $this->processValue($value);
                    $data = $result->getData(true);
                    $totals['processed'] += $data['processed'] ?? 0;
                    $totals['duplicates'] += $data['duplicates'] ?? 0;
                } catch (\Throwable $e) {
                    Log::error('WhatsApp webhook: change handler threw', [
                        'error' => $e->getMessage(),
                        'field' => $change['field'] ?? null,
                    ]);
                    $totals['errors']++;
                }
            }
        }

        return response()->json(['status' => 'ok'] + $totals);
    }

    /**
     * Verification handshake (GET). Different BSPs use different challenge
     * patterns at setup time:
     *   • Meta direct:       ?hub.mode=subscribe&hub.verify_token=X&hub.challenge=Y
     *   • Generic GET:       ?challenge=Y  (no token check)
     *   • Setup probe:       (no params)   — accept any GET as 200 OK
     *
     * Token verification is enforced only when both sides have one set;
     * with no token configured the endpoint accepts all handshakes
     * (which is fine for first-time wire-up where the BSP just wants
     * to confirm reachability + correct response shape).
     */
    public function verify(Request $request)
    {
        $challengeResponse = $this->maybeEchoChallenge($request, []);
        if ($challengeResponse !== null) {
            return $challengeResponse;
        }

        // No challenge param at all — most BSPs accept any 200 here.
        Log::info('WhatsApp webhook: GET with no challenge param', [
            'query' => $request->query(),
        ]);
        return response()->json(['status' => 'ok']);
    }

    /**
     * Echo back the BSP's verification challenge in whichever shape it
     * was sent. Returns null when no recognisable challenge is present,
     * letting the caller continue with normal request handling.
     *
     * Covers:
     *   - Meta-style GET:  ?hub.mode=subscribe&hub.verify_token=X&hub.challenge=Y
     *   - Generic GET:     ?challenge=Y
     *   - JSON POST body:  {"challenge": "Y"}  (also "hub.challenge", "verifyToken")
     *
     * If a token is configured AND the BSP supplied one, both must match
     * (hash_equals) before we echo. If no token is configured, we accept
     * the challenge — first-time setup typically can't authenticate yet.
     */
    private function maybeEchoChallenge(Request $request, array $payload): ?\Symfony\Component\HttpFoundation\Response
    {
        $configuredToken = SystemSetting::getValue(
            'whatsapp_webhook_token',
            config('whatsapp.webhook_token', ''),
        );

        // ---- Meta-style: ?hub.mode=subscribe&hub.verify_token=X&hub.challenge=Y
        // Laravel coerces "hub.mode" → "hub_mode" in $request->query(); both
        // forms are accepted via the raw query string fallback.
        $mode = $request->query('hub_mode') ?? $request->input('hub.mode');
        $hubToken = $request->query('hub_verify_token') ?? $request->input('hub.verify_token');
        $hubChallenge = $request->query('hub_challenge') ?? $request->input('hub.challenge');

        if ($mode === 'subscribe' && $hubChallenge !== null) {
            if ($configuredToken !== '' && ! hash_equals($configuredToken, (string) $hubToken)) {
                Log::warning('WhatsApp webhook: Meta hub.verify_token mismatch');
                return response('forbidden', 403);
            }
            Log::info('WhatsApp webhook: Meta-style verification ok');
            return response((string) $hubChallenge, 200);
        }

        // ---- Generic challenge in GET query (?challenge=X) — and the
        // typo'd variant ?challange=X used by The Internet Store BSP
        // (per their documentation; the misspelling is consistent in
        // their UI's error toast, their docs, and their PHP/Node code
        // samples, so we honour the misspelling verbatim).
        $genericChallenge = $request->query('challenge') ?? $request->query('challange');
        if ($genericChallenge !== null) {
            Log::info('WhatsApp webhook: generic GET challenge ok');
            // Plain text body. BSPs that expect text/html and BSPs that
            // expect text/plain both accept this — only the body
            // content matters for the challenge-response check.
            return response((string) $genericChallenge, 200);
        }

        // ---- POST body { "challenge": "X" } — BSPs that wrap their probe
        // as JSON. Some send { "verifyToken": "X" }; mirror that too.
        // Include the "challange" misspelling here too for completeness.
        $bodyChallenge = $payload['challenge']
            ?? $payload['challange']
            ?? $payload['hub.challenge']
            ?? $payload['verifyToken']
            ?? $payload['verify_token']
            ?? null;
        if ($bodyChallenge !== null && (count($payload) <= 3)) {
            Log::info('WhatsApp webhook: POST-body challenge ok', [
                'payload_keys' => array_keys($payload),
            ]);
            // Echo as JSON because that's what most JSON-body BSPs expect
            // when they posted JSON. Plain-text body also accepted by all
            // BSPs that send JSON challenges, so we lose nothing.
            return response()->json(['challenge' => (string) $bodyChallenge]);
        }

        return null;
    }

    /**
     * Handle the 1automations.com BSP wrapper shape (see handle() for
     * the example). Translate it into the same {message_id, status,
     * error_code, error_message} fields the Meta-format path produces,
     * then write the audit row + propagate onto the matching
     * notification_log.
     *
     * Important quirk for FAILED events: the BSP omits Meta's wamid
     * (because Meta never accepted the message), so the only id we
     * can correlate against is the BSP's own `queue_id`. We store the
     * queue_id in temple_notification_logs.provider_message_id at send
     * time so failure events match by it.
     */
    private function processBspWrappedEvent(array $payload): JsonResponse
    {
        $bspMessage = $payload['message'] ?? [];
        $bspResponse = $payload['response'] ?? [];
        $queueId = $bspMessage['queue_id'] ?? null;
        $status = $bspMessage['message_status'] ?? null;

        if (! $queueId || ! $status) {
            Log::warning('WhatsApp webhook: BSP event missing queue_id or status', [
                'keys' => array_keys($payload),
            ]);
            $this->recordMetadata(WhatsAppWebhookEvent::KIND_UNKNOWN, $payload);
            return response()->json(['status' => 'invalid_bsp_event'], 200);
        }

        // Map BSP's 'message_status' values to our enum. 'sent' from the
        // BSP means Meta-accepted (which is our 'sent'); their 'failed'
        // is our 'failed', etc. They sometimes also emit 'accepted'
        // which is pre-Meta-accept — treat as our 'sent'.
        $normalisedStatus = match ($status) {
            'accepted', 'sent', 'submitted' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed', 'rejected' => 'failed',
            'undelivered' => 'undelivered',
            default => $status,
        };

        // Extract Meta wamid (success) OR Meta error (failure) from the
        // wrapper's `response` field. Both come from Meta verbatim.
        $metaWamid = $bspResponse['messages'][0]['id'] ?? null;
        $error = $bspResponse['error'] ?? null;
        $errorCode = isset($error['code']) ? (int) $error['code'] : null;
        $errorMessage = $error['message']
            ?? $error['error_data']['details']
            ?? null;

        // Dedup on (queue_id, status) — same delivery retried by BSP
        // is a no-op. Use queue_id because Meta wamid may be absent
        // (on failure) but queue_id always present.
        $now = now();
        $inserted = WhatsAppWebhookEvent::query()->insertOrIgnore([
            'event_kind' => WhatsAppWebhookEvent::KIND_MESSAGE_STATUS,
            'message_id' => $metaWamid ?? $queueId,
            'status' => $normalisedStatus,
            'recipient_id' => null, // BSP doesn't include recipient in this envelope
            'error_code' => $errorCode,
            'error_message' => is_string($errorMessage) ? substr($errorMessage, 0, 1000) : null,
            'payload' => json_encode($payload),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate']);
        }

        // Match the notification_log by EITHER the Meta wamid (success
        // path) OR the BSP queue_id (failure path). WhatsAppService
        // stores whichever is available into provider_message_id at
        // send time, so this lookup catches both.
        $log = NotificationLog::query()
            ->where(function ($q) use ($metaWamid, $queueId) {
                if ($metaWamid) $q->orWhere('provider_message_id', $metaWamid);
                if ($queueId)   $q->orWhere('provider_message_id', $queueId);
            })
            ->first();

        if ($log && $this->isStatusProgression($log->delivery_status, $normalisedStatus)) {
            $log->forceFill([
                'delivery_status' => $normalisedStatus,
                'delivery_status_at' => $now,
                'failure_reason' => $normalisedStatus === 'failed' && $errorMessage
                    ? substr((string) $errorMessage, 0, 1000)
                    : $log->failure_reason,
            ])->save();
        }

        Log::info('WhatsApp webhook: BSP-wrapped event recorded', [
            'queue_id' => $queueId,
            'meta_wamid' => $metaWamid,
            'status' => $normalisedStatus,
            'error_code' => $errorCode,
            'matched_log' => $log?->id,
        ]);

        return response()->json(['status' => 'processed']);
    }

    /**
     * Process a single `value{}` object from Meta's payload. Routes to
     * status / inbound / metadata handlers based on which keys are
     * present. Returns a JSON response with per-handler counts so the
     * top-level handle() can aggregate across multiple changes.
     */
    private function processValue(array $value): JsonResponse
    {
        $processed = 0;
        $duplicates = 0;

        // Status events — sent / delivered / read / failed for messages
        // we originally sent. This is the 95% case in practice.
        foreach (($value['statuses'] ?? []) as $status) {
            $result = $this->recordStatus($status, $value);
            if ($result === 'processed') $processed++;
            if ($result === 'duplicate') $duplicates++;
        }

        // Inbound replies. Logged but not yet routed to any handler;
        // template-fired notifications generally don't expect replies.
        foreach (($value['messages'] ?? []) as $message) {
            $this->recordInbound($message, $value);
            $processed++;
        }

        // Template approval / rejection / account / phone updates etc.
        // Logged so support can see them; no business logic wired.
        if (! empty($value['message_template_status_update'])) {
            $this->recordMetadata(WhatsAppWebhookEvent::KIND_TEMPLATE_STATUS, $value);
            $processed++;
        }
        if (! empty($value['account_review_update']) || ! empty($value['account_update'])) {
            $this->recordMetadata(WhatsAppWebhookEvent::KIND_ACCOUNT_UPDATE, $value);
            $processed++;
        }
        if (! empty($value['phone_number_quality_update']) || ! empty($value['phone_number_name_update'])) {
            $this->recordMetadata(WhatsAppWebhookEvent::KIND_PHONE_UPDATE, $value);
            $processed++;
        }

        // Anything else gets logged as unknown so the BSP control-panel
        // event "Outgoing Messages" (and any future field Meta adds)
        // shows up in the audit trail rather than disappearing.
        if ($processed === 0 && $duplicates === 0) {
            $this->recordMetadata(WhatsAppWebhookEvent::KIND_UNKNOWN, $value);
            $processed++;
        }

        return response()->json(['processed' => $processed, 'duplicates' => $duplicates]);
    }

    /**
     * Persist a single status event and propagate it onto the matching
     * notification log row if we have one.
     *
     * Meta status object shape:
     *   { id: "wamid.XXX", status: "delivered", timestamp: "1234567890",
     *     recipient_id: "919876543210", errors: [{code, title, message}] }
     */
    private function recordStatus(array $status, array $valueContext): string
    {
        $messageId = $status['id'] ?? null;
        $statusName = $status['status'] ?? null;
        if (! $messageId || ! $statusName) {
            Log::warning('WhatsApp webhook: status event missing id or status', $status);
            return 'invalid';
        }

        $errors = $status['errors'] ?? [];
        $firstError = is_array($errors) && ! empty($errors) ? $errors[0] : null;
        $errorCode = isset($firstError['code']) ? (int) $firstError['code'] : null;
        $errorMessage = $firstError['message']
            ?? $firstError['title']
            ?? $firstError['error_data']['details']
            ?? null;

        // insertOrIgnore on the (message_id, status) unique key — a
        // retry-delivered status that we've already processed becomes a
        // no-op without touching the notification log row again.
        $now = now();
        $inserted = WhatsAppWebhookEvent::query()->insertOrIgnore([
            'event_kind' => WhatsAppWebhookEvent::KIND_MESSAGE_STATUS,
            'message_id' => $messageId,
            'status' => $statusName,
            'recipient_id' => $status['recipient_id'] ?? ($valueContext['metadata']['display_phone_number'] ?? null),
            'error_code' => $errorCode,
            'error_message' => is_string($errorMessage) ? substr($errorMessage, 0, 1000) : null,
            'payload' => json_encode($status),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return 'duplicate';
        }

        // Propagate the latest status onto the notification log row, if
        // the original send recorded a provider_message_id. Multiple
        // statuses arrive for the same message (sent → delivered →
        // read), so we update last-write-wins — but only if the new
        // status is "later" in the lifecycle so a late-arriving "sent"
        // never overwrites a "delivered". `failed` is terminal and
        // overrides everything for support visibility.
        $log = NotificationLog::where('provider_message_id', $messageId)->first();
        if ($log) {
            $shouldOverwrite = $this->isStatusProgression($log->delivery_status, $statusName);
            if ($shouldOverwrite) {
                $log->forceFill([
                    'delivery_status' => $statusName,
                    'delivery_status_at' => isset($status['timestamp'])
                        ? \Carbon\Carbon::createFromTimestamp((int) $status['timestamp'])
                        : $now,
                    'failure_reason' => $statusName === 'failed' && $errorMessage
                        ? substr((string) $errorMessage, 0, 1000)
                        : $log->failure_reason,
                ])->save();
            }
        }

        Log::info('WhatsApp webhook: status recorded', [
            'message_id' => $messageId,
            'status' => $statusName,
            'error_code' => $errorCode,
            'matched_log' => $log?->id,
        ]);

        return 'processed';
    }

    /**
     * Lifecycle progression: sent (0) → delivered (1) → read (2).
     * `failed` (terminal) and `undelivered` (transient) override anything
     * less terminal because admins care about failures over progress.
     */
    private function isStatusProgression(?string $current, string $incoming): bool
    {
        $rank = [
            'sent' => 0,
            'undelivered' => 1,
            'delivered' => 2,
            'read' => 3,
            'failed' => 100, // always wins; terminal
        ];
        $currentRank = $rank[$current] ?? -1;
        $incomingRank = $rank[$incoming] ?? 0;
        return $incomingRank >= $currentRank;
    }

    private function recordInbound(array $message, array $valueContext): void
    {
        WhatsAppWebhookEvent::query()->create([
            'event_kind' => WhatsAppWebhookEvent::KIND_INBOUND_MESSAGE,
            'message_id' => $message['id'] ?? null,
            'status' => null,
            'recipient_id' => $message['from'] ?? null,
            'payload' => json_encode($message),
            'received_at' => now(),
        ]);
        Log::info('WhatsApp webhook: inbound message received', [
            'from' => $message['from'] ?? null,
            'type' => $message['type'] ?? null,
        ]);
    }

    private function recordMetadata(string $kind, array $value): void
    {
        WhatsAppWebhookEvent::query()->create([
            'event_kind' => $kind,
            'payload' => json_encode($value),
            'received_at' => now(),
        ]);
        Log::info("WhatsApp webhook: {$kind} event recorded");
    }
}
