<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Msg91WebhookEvent;
use App\Models\NotificationLog;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound MSG91 SMS delivery-report (DLR) receiver.
 *
 * WHY THIS ENDPOINT IS THE ONLY SOURCE OF TRUTH FOR SMS
 * ------------------------------------------------------
 * MSG91's Flow API validates nothing synchronously. Measured against the
 * live trust account on 2026-08-10:
 *   • a POST with a deliberately WRONG auth key answers HTTP 200
 *     {"type":"success"};
 *   • the legacy balance.php returns 0 for a wrong key as readily as for
 *     a right one.
 * The trust's actual failure ("Template ID Missing or Invalid Template")
 * appeared only in MSG91's own dashboard. So "we sent it" is, at send
 * time, never more than "MSG91 accepted the bytes". This webhook is the
 * only mechanism by which the platform can learn what really happened,
 * and it is the ONLY thing permitted to mark a notification log row
 * delivered or failed.
 *
 * PROTECTION (read this before believing the endpoint is authenticated)
 * ---------------------------------------------------------------------
 * MSG91's delivery-report setting is a bare URL field: no signing secret,
 * no HMAC, no custom header. The endpoint is therefore a CAPABILITY URL —
 * a long random token in the path, compared with hash_equals. That is
 * unguessable and rotatable, but it is NOT authentication: anyone who
 * obtains the URL can POST to it. The blast radius is deliberately
 * bounded — a forged report can only change the delivery status shown
 * against an already-sent message. It cannot cause a send, read devotee
 * data, or touch money. See SmsService::webhookToken().
 *
 * OPERATIONAL RULES
 * -----------------
 *   • NEVER 500, and never answer non-2xx for a payload problem. MSG91
 *     retries on failure, forever, and a parse bug would turn one bad
 *     report into a permanent retry storm. Anything unexpected is logged
 *     and answered 200.
 *   • Idempotent. A deterministic event_key (request id + recipient +
 *     status + MSG91 timestamp) is UNIQUE; insertOrIgnore makes a retried
 *     report a no-op rather than a second write to the log row.
 *   • PII. The report carries the full mobile number. It is masked before
 *     it is stored, before it is logged, and inside the retained raw JSON.
 *
 * PAYLOAD SHAPES
 * --------------
 * MSG91 DLR formats vary by account, route and vintage. All of these are
 * handled, and the raw JSON is kept regardless so a shape we guessed
 * wrong about is still recoverable:
 *
 *   [ { "requestId": "…", "data":   [ {number, status, desc, date}, … ] } ]
 *   { "requestId": "…", "report": [ {number, status, desc, date}, … ] }
 *   { "requestId": "…", "number": "…", "status": "1", "desc": "DELIVERED" }
 *   GET/POST form fields with the same names (older callback style).
 */
class Msg91WebhookController extends Controller
{
    /**
     * MSG91 numeric DLR codes.
     *
     * Only 1 means the handset got it and only 8 means "still in
     * flight"; every other code in MSG91's table is a non-delivery of
     * some kind (DND, absent subscriber, operator rejection, invalid
     * template, expired). We bucket the rest as failed rather than
     * enumerate a list that varies by operator — and the verbatim
     * `desc` is preserved either way, which is the field an admin
     * actually needs.
     */
    private const NUMERIC_STATUS = [
        '1' => NotificationLog::DELIVERY_DELIVERED,
        '8' => NotificationLog::DELIVERY_SENT,
    ];

    public function handle(Request $request, string $token): JsonResponse
    {
        if (! $this->tokenMatches($token)) {
            // 403, not 200: a wrong token is not a delivery report, and
            // MSG91 retrying it forever is the correct outcome for a URL
            // that was rotated (it stops once the trust pastes the new
            // one in). Never echo the expected token or its length.
            Log::warning('MSG91 webhook: rejected — token mismatch', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'forbidden'], 403);
        }

        try {
            $payload = $this->readPayload($request);

            if ($payload === []) {
                Log::warning('MSG91 webhook: empty payload', ['ip' => $request->ip()]);

                // 200 on purpose — see OPERATIONAL RULES.
                return response()->json(['status' => 'ignored', 'reason' => 'empty payload']);
            }

            $reports = $this->extractReports($payload);

            if ($reports === []) {
                // Unrecognised shape. Keep it anyway, under a null
                // status, so the shape can be inspected later and the
                // parser taught about it — losing it would be worse.
                $this->store(
                    reportedAt: null,
                    requestId: $this->firstString($payload, ['requestId', 'request_id', 'requestID']),
                    phone: null,
                    statusCode: null,
                    providerStatus: null,
                    description: null,
                    deliveryStatus: null,
                    rawPayload: $payload,
                );

                Log::warning('MSG91 webhook: unrecognised payload shape (stored raw)', [
                    'keys' => array_keys($payload),
                ]);

                return response()->json(['status' => 'stored', 'parsed' => 0]);
            }

            $processed = 0;
            $duplicates = 0;

            foreach ($reports as $report) {
                $outcome = $this->processReport($report['request_id'], $report['fields'], $payload);
                $outcome === 'duplicate' ? $duplicates++ : $processed++;
            }

            return response()->json([
                'status' => 'ok',
                'processed' => $processed,
                'duplicates' => $duplicates,
            ]);
        } catch (\Throwable $e) {
            // A parse/DB failure must not become an infinite MSG91 retry
            // loop. Log loudly, answer 200.
            Log::error('MSG91 webhook: handler threw (answered 200 to stop retries)', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json(['status' => 'error_logged'], 200);
        }
    }

    /**
     * Constant-time comparison of the URL token.
     *
     * An unset/short stored token can never match: without this guard a
     * blank setting would turn the endpoint into an open URL the moment
     * someone requested /api/webhooks/msg91/ with anything at all.
     */
    private function tokenMatches(string $token): bool
    {
        $expected = (string) \App\Models\SystemSetting::getValue(SmsService::WEBHOOK_TOKEN_KEY, '');

        if (strlen($expected) < 32) {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * MSG91 posts JSON on modern accounts and form-encoded fields (or a
     * plain query string) on older callback configurations. Take whatever
     * is there.
     *
     * @return array<mixed>
     */
    private function readPayload(Request $request): array
    {
        $json = $request->json()->all();
        if (is_array($json) && $json !== []) {
            return $json;
        }

        $all = $request->all();

        return is_array($all) ? $all : [];
    }

    /**
     * Flatten every supported envelope into a list of
     * {request_id, fields} pairs.
     *
     * @param  array<mixed>  $payload
     * @return array<int, array{request_id: ?string, fields: array<string, mixed>}>
     */
    private function extractReports(array $payload): array
    {
        // A bare list at the top level: [ {requestId, data:[…]}, … ].
        if (array_is_list($payload)) {
            $out = [];
            foreach ($payload as $entry) {
                if (is_array($entry)) {
                    $out = array_merge($out, $this->extractReports($entry));
                }
            }

            return $out;
        }

        $requestId = $this->firstString($payload, ['requestId', 'request_id', 'requestID', 'msgId', 'message_id']);

        // Envelope with a nested rows array under any of MSG91's names.
        foreach (['data', 'report', 'reports', 'records', 'recipients'] as $key) {
            $rows = $payload[$key] ?? null;
            if (is_array($rows) && $rows !== [] && array_is_list($rows)) {
                $out = [];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $out[] = [
                            // A per-row request id wins over the envelope's.
                            'request_id' => $this->firstString($row, ['requestId', 'request_id', 'requestID']) ?? $requestId,
                            'fields' => $row,
                        ];
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        // Flat single report — recognised only if it actually carries a
        // status field, otherwise we would manufacture a report out of an
        // arbitrary POST body.
        if ($this->firstString($payload, ['status', 'statusCode', 'status_code', 'desc', 'description']) !== null) {
            return [['request_id' => $requestId, 'fields' => $payload]];
        }

        return [];
    }

    /**
     * Persist one delivery report and, if it correlates, apply it to the
     * notification log row.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<mixed>  $rawPayload
     * @return string 'processed' | 'duplicate'
     */
    private function processReport(?string $requestId, array $fields, array $rawPayload): string
    {
        $phone = $this->firstString($fields, ['number', 'mobile', 'msisdn', 'recipient', 'to', 'phone']);
        $rawStatus = $this->firstString($fields, ['status', 'statusCode', 'status_code', 'dlr_status']);
        $description = $this->firstString($fields, ['desc', 'description', 'reason', 'statusDesc', 'error', 'errorMessage']);
        $reportedAt = $this->parseDate($this->firstString($fields, ['date', 'deliveryDate', 'datetime', 'timestamp', 'dateTime']));

        $deliveryStatus = $this->normaliseStatus($rawStatus, $description);

        [$event, $isDuplicate] = $this->store(
            reportedAt: $reportedAt,
            requestId: $requestId,
            phone: $phone,
            statusCode: $rawStatus,
            providerStatus: $rawStatus,
            description: $description,
            deliveryStatus: $deliveryStatus,
            rawPayload: $rawPayload,
        );

        if ($isDuplicate) {
            Log::info('MSG91 webhook: duplicate report ignored', [
                'request_id' => $requestId,
                'status' => $rawStatus,
            ]);

            return 'duplicate';
        }

        $log = $this->matchNotificationLog($requestId, $phone);

        if ($log !== null) {
            $this->applyToLog($log, $deliveryStatus, $description, $rawStatus, $reportedAt);

            if ($event !== null) {
                $event->forceFill(['notification_log_id' => $log->getKey()])->save();
            }
        }

        Log::info('MSG91 webhook: delivery report recorded', [
            'request_id' => $requestId,
            'phone' => $phone !== null ? SmsService::maskPhone($phone) : null,
            'provider_status' => $rawStatus,
            'delivery_status' => $deliveryStatus,
            // MSG91's own words — the single most useful line in the log.
            'msg91_reason' => $description,
            'matched_log' => $log?->getKey(),
        ]);

        return 'processed';
    }

    /**
     * Insert the audit row, deduped on a synthesised event key.
     *
     * @param  array<mixed>  $rawPayload
     * @return array{0: ?Msg91WebhookEvent, 1: bool} [event, wasDuplicate]
     */
    private function store(
        ?\Carbon\CarbonInterface $reportedAt,
        ?string $requestId,
        ?string $phone,
        ?string $statusCode,
        ?string $providerStatus,
        ?string $description,
        ?string $deliveryStatus,
        array $rawPayload,
    ): array {
        // MSG91 issues no event id, so synthesise a stable one from the
        // fields that identify a single (message, status) transition. A
        // retried POST reproduces it byte-for-byte and collapses on the
        // unique index; a genuine later transition (sent → delivered)
        // produces a different key and is recorded as its own event.
        $eventKey = hash('sha256', implode('|', [
            $requestId ?? '',
            $phone !== null ? (preg_replace('/\D/', '', $phone) ?? '') : '',
            $statusCode ?? '',
            $reportedAt?->toIso8601String() ?? '',
            // Guard against an envelope with neither id nor status: fall
            // back to the payload itself so two genuinely different
            // unparsed reports do not collide into one row.
            $requestId === null && $statusCode === null
                ? md5((string) json_encode($rawPayload))
                : '',
        ]));

        $now = now();
        $inserted = Msg91WebhookEvent::query()->insertOrIgnore([
            'event_key' => $eventKey,
            'request_id' => $requestId !== null ? substr($requestId, 0, 96) : null,
            // Masked, always. The raw number never lands in a column.
            'recipient_masked' => $phone !== null ? SmsService::maskPhone($phone) : null,
            'recipient_hash' => $phone !== null ? Msg91WebhookEvent::hashRecipient($phone) : null,
            'status_code' => $statusCode !== null ? substr($statusCode, 0, 16) : null,
            'provider_status' => $providerStatus !== null ? substr($providerStatus, 0, 64) : null,
            // MSG91's wording, verbatim — never rephrased, never
            // summarised. Truncation only guards against a pathological
            // provider response.
            'description' => $description !== null ? substr($description, 0, 2000) : null,
            'delivery_status' => $deliveryStatus,
            'reported_at' => $reportedAt,
            'payload' => json_encode(Msg91WebhookEvent::redactPayload($rawPayload)),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return [null, true];
        }

        Msg91WebhookEvent::forgetReportingCache();

        return [Msg91WebhookEvent::query()->where('event_key', $eventKey)->first(), false];
    }

    /**
     * Find the notification log row this report belongs to.
     *
     * The join key is MSG91's request id, captured at send time by
     * SmsService::sendTemplate() and stored on provider_message_id. When
     * one submission fanned out to several recipients, the masked-phone
     * hash disambiguates.
     */
    private function matchNotificationLog(?string $requestId, ?string $phone): ?NotificationLog
    {
        if ($requestId === null || $requestId === '') {
            return null;
        }

        $query = NotificationLog::query()
            ->where('provider_message_id', $requestId)
            ->where('channel', 'sms')
            ->orderByDesc('id');

        $candidates = $query->limit(10)->get();

        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1 || $phone === null) {
            return $candidates->first();
        }

        // Compare on the last 10 digits: the log stores what the app had
        // ("9876543210"), MSG91 reports back with the country code
        // ("919876543210").
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -10);

        return $candidates->first(function (NotificationLog $candidate) use ($tail): bool {
            $value = (string) ($candidate->recipient_value ?? '');

            return $tail !== '' && substr(preg_replace('/\D/', '', $value) ?? '', -10) === $tail;
        }) ?? $candidates->first();
    }

    /**
     * Apply the report to the log row.
     *
     * This is the ONLY place an SMS row is allowed to become `delivered`
     * or `failed`. The send path records the intermediate 'sent'
     * (= accepted by MSG91, delivery unknown) and nothing more.
     */
    private function applyToLog(
        NotificationLog $log,
        ?string $deliveryStatus,
        ?string $description,
        ?string $rawStatus,
        ?\Carbon\CarbonInterface $reportedAt,
    ): void {
        if ($deliveryStatus === null) {
            // A status we do not recognise. Record MSG91's words against
            // the row so an admin can still read them, but do not claim a
            // delivery outcome we cannot justify.
            if ($description !== null) {
                $log->forceFill([
                    'failure_reason' => substr("MSG91 status {$rawStatus}: {$description}", 0, 1000),
                ])->save();
            }

            return;
        }

        if (! $this->isStatusProgression($log->delivery_status, $deliveryStatus)) {
            return;
        }

        $updates = [
            'delivery_status' => $deliveryStatus,
            'delivery_status_at' => $reportedAt ?? now(),
        ];

        if ($deliveryStatus === NotificationLog::DELIVERY_FAILED) {
            // MSG91's reason, verbatim, prefixed only with its own status
            // code so support can quote both back to MSG91 support. This
            // sentence is the entire point of the integration.
            $updates['failure_reason'] = substr(
                trim(($description ?? 'MSG91 reported a delivery failure')
                    . ($rawStatus !== null ? " (MSG91 status {$rawStatus})" : '')),
                0,
                1000,
            );

            // The send attempt itself is now known to have failed. Flip
            // the send status too so failure filters and the Resend
            // action see it.
            $updates['status'] = NotificationLog::STATUS_FAILED;
            $updates['error_message'] = $updates['failure_reason'];
        }

        $log->forceFill($updates)->save();
    }

    /**
     * Map MSG91's status onto the delivery_status vocabulary already used
     * by the WhatsApp webhook. Returns null when MSG91 sent something we
     * do not recognise — the caller then records the event without
     * touching the log's delivery state rather than guessing.
     */
    private function normaliseStatus(?string $rawStatus, ?string $description): ?string
    {
        $haystack = strtolower(trim(($rawStatus ?? '') . ' ' . ($description ?? '')));

        if ($haystack === '') {
            return null;
        }

        // Textual first — it is unambiguous where present.
        if (str_contains($haystack, 'deliver') && ! str_contains($haystack, 'undeliver') && ! str_contains($haystack, 'not deliver')) {
            return NotificationLog::DELIVERY_DELIVERED;
        }
        foreach (['fail', 'reject', 'block', 'expire', 'invalid', 'absent', 'dnd', 'ndnc', 'opt out', 'undeliver', 'error'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return NotificationLog::DELIVERY_FAILED;
            }
        }
        foreach (['queue', 'submit', 'accept', 'enroute', 'pending', 'sent'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return NotificationLog::DELIVERY_SENT;
            }
        }

        // Numeric code fallback.
        $code = trim((string) $rawStatus);
        if ($code !== '' && ctype_digit($code)) {
            return self::NUMERIC_STATUS[$code] ?? NotificationLog::DELIVERY_FAILED;
        }

        return null;
    }

    /**
     * sent (submitted) → delivered. `failed` is terminal and always wins,
     * because a failure an admin cannot see is the whole problem this
     * integration exists to solve. Same ranking the WhatsApp webhook uses.
     */
    private function isStatusProgression(?string $current, string $incoming): bool
    {
        $rank = [
            NotificationLog::DELIVERY_SENT => 0,
            NotificationLog::DELIVERY_UNDELIVERED => 1,
            NotificationLog::DELIVERY_DELIVERED => 2,
            NotificationLog::DELIVERY_READ => 3,
            NotificationLog::DELIVERY_FAILED => 100,
        ];

        return ($rank[$incoming] ?? 0) >= ($rank[$current] ?? -1);
    }

    /**
     * First non-empty scalar among a list of candidate keys.
     *
     * @param  array<mixed>  $source
     * @param  array<int, string>  $keys
     */
    private function firstString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** MSG91 timestamps vary in format; an unparseable one is not fatal. */
    private function parseDate(?string $value): ?\Carbon\CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (ctype_digit($value) && strlen($value) >= 9) {
                return \Carbon\Carbon::createFromTimestamp((int) $value);
            }

            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
