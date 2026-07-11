<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Devotee;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Support\ReviewBypass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central notification dispatcher.
 *
 *   app(NotificationService::class)->dispatch('donation.confirmed', [
 *       'donation' => $donation,
 *       'devotee'  => $donation->devotee,
 *   ]);
 *
 * Resolves every enabled NotificationTemplate row matching `$key` and
 * fans out across channels. Channels are pluggable drivers registered
 * in NotificationServiceProvider; this class owns the rest:
 *
 *   • **Transaction safety** — dispatch() registers a DB::afterCommit
 *     callback. If the caller is inside a transaction, the sends only
 *     fire after that transaction commits. A rolled-back transaction
 *     never produces a "confirmation" notification for work that
 *     didn't happen.
 *
 *   • **Audit log** — every (template × recipient) attempt writes a
 *     NotificationLog row (pending → sent / failed / skipped). Admins
 *     read these from the Filament NotificationLogResource to debug
 *     "did the donor receive their 80G receipt?".
 *
 *   • **Idempotency** — callers may pass an idempotency key (e.g.
 *     "payment:{$paymentId}:donation.confirmed"). If a NotificationLog
 *     row with the same key + channel exists within the dedup window
 *     (5 min), the duplicate is logged as `skipped` and not re-sent.
 *     Belt-and-braces for the webhook + verify race.
 *
 *   • **HTTP deferral** — inside an HTTP request the actual SMTP /
 *     WhatsApp work is deferred to `app()->terminating()`, which fires
 *     AFTER the response is flushed (via PHP-FPM's
 *     fastcgi_finish_request). User sees <1s response, recipient sees
 *     the email 5-10s later as PHP-FPM finishes its worker lifecycle.
 *
 *   • **Failures never throw** — every per-template send is wrapped in
 *     try/catch. A misconfigured WhatsApp template cannot break the
 *     payment-capture transaction that triggered it.
 */
final class NotificationService
{
    /**
     * Window in which a repeat dispatch with the same idempotency key
     * skips. Set to 30 min so cron-driven dispatches (which fire on a
     * fixed cadence) can't slip through a too-narrow boundary —
     * eg the seva reminder cron at HH:00 and HH:05 used to both fire
     * the same reminder because the second tick landed 1 second past
     * a 5-min dedup window.
     *
     * 30 min is also well within "no legitimate re-dispatch of the
     * same key wanted" for every existing trigger (donation receipt,
     * OTP, booking confirmation, birthday) — re-fires for those flows
     * happen via a fresh key (new payment id, new OTP, etc), not via
     * the same key inside the window.
     */
    private const IDEMPOTENCY_WINDOW_SECONDS = 1800;

    /** @var array<string, NotificationDriver> keyed by channel string */
    private array $drivers = [];

    /** @param iterable<NotificationDriver> $drivers */
    public function __construct(
        iterable $drivers,
        private readonly RecipientResolver $recipients,
    ) {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->channel()] = $driver;
        }
    }

    /**
     * Fire every enabled template for $key. Safe to call inside a DB
     * transaction — sends are deferred until after the transaction
     * commits, so a rolled-back caller never produces user-facing
     * confirmations.
     *
     * @param array<string,mixed> $context
     * @param string|null $idempotencyKey  Optional dedup token; same key + channel within 5 minutes is skipped.
     * @param list<string>|null $onlyChannels  When non-null, restrict this dispatch to these channels
     *                                         (e.g. ['email','whatsapp']); enabled templates on other
     *                                         channels are left untouched. Null = every channel, as before.
     *
     * @return int Templates delivered when running inline; 0 when deferred
     *             (the actual count is recorded in NotificationLog rows).
     */
    public function dispatch(string $key, array $context, ?string $idempotencyKey = null, ?array $onlyChannels = null): int
    {
        // DB::afterCommit fires immediately when no transaction is active,
        // so this wrapping is always safe — even outside a transaction
        // context the call behaves exactly like before.
        DB::afterCommit(function () use ($key, $context, $idempotencyKey, $onlyChannels) {
            // CLI / queue worker — no response to flush, run synchronously.
            if (app()->runningInConsole()) {
                $this->doDispatch($key, $context, $idempotencyKey, $onlyChannels);
                return;
            }

            // HTTP — defer expensive driver work to after the response is
            // flushed. The terminating callback runs in the same PHP-FPM
            // worker after fastcgi_finish_request.
            app()->terminating(function () use ($key, $context, $idempotencyKey, $onlyChannels) {
                try {
                    $this->doDispatch($key, $context, $idempotencyKey, $onlyChannels);
                } catch (\Throwable $e) {
                    Log::error('Notification: deferred dispatch threw', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        });

        return 0;
    }

    /**
     * Force-inline dispatch. Used by:
     *   • CLI commands (`auth.otp` from Tinker, birthday job)
     *   • The Filament "Send test" action which needs synchronous feedback
     *
     * Still writes audit log rows. Idempotency is honored — pass a fresh
     * key (or null) when re-running deliberately.
     */
    public function dispatchNow(string $key, array $context, ?string $idempotencyKey = null, ?array $onlyChannels = null): int
    {
        return $this->doDispatch($key, $context, $idempotencyKey, $onlyChannels);
    }

    /**
     * Send a single specific template, bypassing the enabled flag and the
     * key lookup. Used by the admin "Send test" UI. Writes an audit row.
     */
    public function sendTemplate(NotificationTemplate $template, NotificationContext|array $context): bool
    {
        $ctx = $context instanceof NotificationContext ? $context : new NotificationContext($context);

        return $this->deliver($template, $ctx, idempotencyKey: null);
    }

    /**
     * Re-attempt the delivery recorded by an existing NotificationLog row,
     * updating THAT SAME row in place (status + attempts + sent_at).
     *
     * This is the engine behind the automatic reaper (`notifications:reap`)
     * AND can be reused by the admin "Resend" action. Unlike sendTemplate()
     * — which writes a fresh log row per call — retryLog() mutates the
     * original row so its `attempts` column is a natural, durable retry
     * budget: the reaper simply stops re-attempting once attempts hits its
     * ceiling, with no extra bookkeeping table or column.
     *
     * The idempotency check in deliver() is deliberately bypassed here: the
     * whole point of a retry is that the original send did NOT reach the
     * recipient (the row is still `pending` after the send window, or it is
     * `failed`). The caller (reaper) owns the safety rules that decide a row
     * is genuinely eligible before calling this.
     *
     * Returns true if the driver accepted the message this time.
     */
    public function retryLog(NotificationLog $log): bool
    {
        $template = $log->template;
        if ($template === null) {
            $log->forceFill([
                'status' => NotificationLog::STATUS_FAILED,
                'error_message' => 'auto-retry aborted: source template no longer exists',
            ])->save();
            return false;
        }

        $driver = $this->drivers[$template->channel] ?? null;
        if ($driver === null) {
            $log->forceFill([
                'status' => NotificationLog::STATUS_FAILED,
                'error_message' => "auto-retry aborted: no driver for channel {$template->channel}",
            ])->save();
            return false;
        }

        // Pin the exact (strategy, value) recorded on the log so multi-
        // recipient templates re-send to the SAME recipient that was tried
        // originally, not the template's legacy default. Mirrors the admin
        // Resend action.
        $perRecipientTemplate = (new NotificationTemplate())
            ->setRawAttributes($template->getAttributes(), sync: true);
        $perRecipientTemplate->exists = true;
        if (! empty($log->recipient_strategy)) {
            $perRecipientTemplate->recipient_strategy = $log->recipient_strategy;
            $perRecipientTemplate->recipient_value = $log->recipient_value;
        }

        $ctx = new NotificationContext(self::rehydrateSnapshot($log->context_snapshot ?? []));

        $ok = false;
        $errorMessage = null;
        try {
            $ok = $driver->send($perRecipientTemplate, $ctx);
        } catch (\Throwable $e) {
            $errorMessage = substr($e->getMessage(), 0, 1000);
            Log::error('Notification: retry driver threw', [
                'log_id' => $log->getKey(),
                'template_key' => $template->key,
                'channel' => $template->channel,
                'error' => $errorMessage,
            ]);
        }

        $providerMessageId = method_exists($driver, 'lastMessageId')
            ? $driver->lastMessageId()
            : null;

        $log->forceFill([
            'status' => $ok ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
            'attempts' => $log->attempts + 1,
            'sent_at' => $ok ? now() : null,
            'error_message' => $ok ? null : ($errorMessage ?? 'driver returned false'),
            'provider_message_id' => $ok ? ($providerMessageId ?? $log->provider_message_id) : $log->provider_message_id,
        ])->save();

        return $ok;
    }

    /**
     * Rebuild a dispatch context array from a stored context_snapshot,
     * re-inflating the flattened {model, id} pairs back into real Eloquent
     * instances (RecipientResolver and the drivers do `instanceof Model`
     * checks, so a bare {model, id} array is invisible to them).
     *
     * Rows that have since been deleted are dropped — the resolver then
     * falls back to top-level scalar keys (context.email / context.phone)
     * or fails cleanly, which beats carrying a half-real reference.
     *
     * Public + static so both the reaper and the Filament Resend action
     * share one implementation. See NotificationService::snapshotContext()
     * for the inverse direction.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function rehydrateSnapshot(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot as $key => $value) {
            if (
                is_array($value)
                && isset($value['model'], $value['id'])
                && is_string($value['model'])
                && is_subclass_of($value['model'], Model::class)
            ) {
                $model = $value['model']::query()->find($value['id']);
                if ($model !== null) {
                    $out[$key] = $model;
                }
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Core dispatch loop — resolves templates for $key, fans out by channel
     * and then by recipient inside each template, isolates each send so one
     * dead driver never suppresses the others.
     *
     * A single template row can now declare multiple recipients (see
     * NotificationTemplate::resolveRecipients() — JSON `recipients` column).
     * For each (template × recipient config) we deliver once, and an
     * `admin_role` recipient further fans out into N per-admin deliveries
     * inside the inner loop. The per-recipient template is built via
     * `replicate()` with overridden strategy/value so the in-memory model
     * the driver sees matches what RecipientResolver expects, without ever
     * touching the persisted row.
     */
    /**
     * True when the dispatch's subject is the configured store-review test
     * number — via the raw context.phone scalar or the devotee model's
     * phone. Used to suppress all sends to that throwaway account.
     */
    private function isReviewTestRecipient(array $context): bool
    {
        if (ReviewBypass::isTestPhone($context['phone'] ?? null)) {
            return true;
        }

        $devotee = $context['devotee'] ?? null;
        if ($devotee instanceof Devotee && ReviewBypass::isTestPhone($devotee->phone)) {
            return true;
        }

        return false;
    }

    private function doDispatch(string $key, array $context, ?string $idempotencyKey, ?array $onlyChannels = null): int
    {
        // Store-review test number: never send it anything. Keeps the
        // Apple/Google reviewer's login clean (no welcome message, no
        // receipts) and avoids spending WhatsApp/SMS on a throwaway
        // account. The OTP send itself is already short-circuited in
        // OtpService; this catches every other trigger whose subject is
        // the test devotee (devotee.registered, seva/donation confirms).
        if ($this->isReviewTestRecipient($context)) {
            Log::info('Notification: suppressed for review test number', ['key' => $key]);
            return 0;
        }

        $templatesQuery = NotificationTemplate::query()
            ->where('key', $key)
            ->where('is_enabled', true);

        // Optional per-dispatch channel allow-list. Used by callers that
        // gate delivery per-channel from their own config (e.g. the
        // greeting-card job honouring the donation-type send_via_* toggles).
        // Never widens delivery — an enabled template on an excluded channel
        // simply doesn't fire on this dispatch.
        if ($onlyChannels !== null) {
            if ($onlyChannels === []) {
                Log::info('Notification: dispatch suppressed — empty channel allow-list', ['key' => $key]);
                return 0;
            }
            $templatesQuery->whereIn('channel', $onlyChannels);
        }

        $templates = $templatesQuery->get();

        if ($templates->isEmpty()) {
            Log::info('Notification: no enabled templates for key', ['key' => $key]);
            return 0;
        }

        $ctx = new NotificationContext($context);
        $delivered = 0;

        foreach ($templates as $template) {
            // Idempotency key composition.
            //
            // Before multi-template support, (key, channel) was UNIQUE
            // so {caller-key}:{channel} was enough. Two templates can
            // now share (key, channel) (eg "Seva reminder — devotee
            // push" + "Seva reminder — pujari push"), so we suffix the
            // template id as well — without it, both templates'
            // per-recipient r0 entries hash to the same dedup row and
            // the second template's send is logged as
            // status=skipped/reason=duplicate inside the 5-min window.
            //
            // We further suffix with :r{i} per recipient and :admin:{id}
            // inside the admin_role fan-out so cron re-runs dedup
            // independently per (template × recipient × admin).
            $perTemplateKey = $idempotencyKey !== null
                ? "{$idempotencyKey}:{$template->channel}:t{$template->id}"
                : null;

            $recipientConfigs = $template->resolveRecipients();
            if ($recipientConfigs === []) {
                Log::warning('Notification: template has no usable recipient configs', [
                    'template_id' => $template->id, 'template_key' => $template->key,
                ]);
                continue;
            }

            foreach ($recipientConfigs as $i => $recipient) {
                $strategy = $recipient['strategy'];
                $value = $recipient['value'];

                // Per-recipient view of the template — never persist this
                // back. setRawAttributes copies the model state cleanly;
                // overwriting two attributes after gives RecipientResolver
                // the exact (strategy, value) for this delivery without
                // mutating the original loaded row.
                $perRecipientTemplate = (new NotificationTemplate())
                    ->setRawAttributes($template->getAttributes(), sync: true);
                $perRecipientTemplate->exists = true;
                $perRecipientTemplate->recipient_strategy = $strategy;
                $perRecipientTemplate->recipient_value = $value;

                // Suffix the idempotency key with the recipient index so
                // multi-recipient cron re-runs dedup independently per
                // entry. Without this, the second recipient's first send
                // would alias to the first recipient's prior log row and
                // get skipped as "duplicate".
                $perRecipientKey = $perTemplateKey !== null
                    ? "{$perTemplateKey}:r{$i}"
                    : null;

                // admin_role recipient fans out across every active admin
                // user holding the configured role. Each gets its own
                // delivery with `admin` injected into context so templates
                // can render {{ admin.name }} per recipient.
                if ($strategy === NotificationTemplate::RECIPIENT_ADMIN_ROLE) {
                    $role = is_string($value) ? trim($value) : '';
                    if ($role === '') {
                        Log::warning('Notification: admin_role recipient missing role name', [
                            'template_id' => $template->id, 'template_key' => $template->key,
                        ]);
                        continue;
                    }

                    $admins = \App\Models\AdminUser::role($role)->where('is_active', true)->get();
                    if ($admins->isEmpty()) {
                        Log::info('Notification: no active admins with role — skipping', [
                            'template_id' => $template->id, 'template_key' => $template->key, 'role' => $role,
                        ]);
                        continue;
                    }

                    foreach ($admins as $admin) {
                        $perAdminCtx = $ctx->with(['admin' => $admin]);
                        $perAdminKey = $perRecipientKey !== null
                            ? "{$perRecipientKey}:admin:{$admin->getKey()}"
                            : null;
                        try {
                            if ($this->deliver($perRecipientTemplate, $perAdminCtx, $perAdminKey)) {
                                $delivered++;
                            }
                        } catch (\Throwable $e) {
                            Log::error('Notification: deliver wrapper threw (admin_role fan-out)', [
                                'template_id' => $template->id,
                                'admin_user_id' => $admin->getKey(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    continue;
                }

                try {
                    if ($this->deliver($perRecipientTemplate, $ctx, $perRecipientKey)) {
                        $delivered++;
                    }
                } catch (\Throwable $e) {
                    // The deliver() wrapper itself catches; this is the absolute
                    // safety net so an unexpected throw in error-handling code
                    // can never break the caller.
                    Log::error('Notification: deliver wrapper threw', [
                        'template_id' => $template->id,
                        'template_key' => $template->key,
                        'channel' => $template->channel,
                        'recipient_strategy' => $strategy,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $delivered;
    }

    /**
     * Resolve recipient → write pending log → call driver → finalize log.
     * Single point where every send-attempt's outcome gets recorded.
     */
    private function deliver(
        NotificationTemplate $template,
        NotificationContext $ctx,
        ?string $idempotencyKey,
    ): bool {
        $driver = $this->drivers[$template->channel] ?? null;
        if ($driver === null) {
            Log::warning('Notification: no driver registered for channel', [
                'channel' => $template->channel,
                'template_id' => $template->id,
            ]);
            $this->writeLog($template, $ctx, recipient: null, status: NotificationLog::STATUS_SKIPPED,
                skipReason: 'no_driver', idempotencyKey: $idempotencyKey);
            return false;
        }

        // Idempotency check — has this same dispatch fired in the last 5 min?
        if ($idempotencyKey !== null && $this->isDuplicate($idempotencyKey, $template->channel)) {
            Log::info('Notification: skipped duplicate within dedup window', [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->writeLog($template, $ctx, recipient: null, status: NotificationLog::STATUS_SKIPPED,
                skipReason: 'duplicate', idempotencyKey: $idempotencyKey);
            return false;
        }

        // Recipient resolution — service-side so the log row captures who
        // we tried to reach. Drivers still resolve internally too (they
        // need the address); the cost is one extra cheap DB/setting lookup.
        $recipient = $this->resolveRecipientForLog($template, $ctx);

        $log = $this->writeLog($template, $ctx, $recipient, NotificationLog::STATUS_PENDING,
            skipReason: null, idempotencyKey: $idempotencyKey);

        $ok = false;
        $errorMessage = null;
        try {
            $ok = $driver->send($template, $ctx);
        } catch (\Throwable $e) {
            $errorMessage = substr($e->getMessage(), 0, 1000);
            Log::error('Notification: driver threw', [
                'template_id' => $template->id,
                'template_key' => $template->key,
                'channel' => $template->channel,
                'error' => $errorMessage,
            ]);
        }

        if ($log !== null) {
            // Pull the provider message_id off the driver if it exposes
            // one (WhatsApp does; email/sms/push don't yet). This is the
            // join key for inbound delivery webhook events.
            $providerMessageId = method_exists($driver, 'lastMessageId')
                ? $driver->lastMessageId()
                : null;

            $log->forceFill([
                'status' => $ok ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
                'attempts' => $log->attempts + 1,
                'sent_at' => $ok ? now() : null,
                'error_message' => $ok ? null : ($errorMessage ?? 'driver returned false'),
                'provider_message_id' => $ok ? $providerMessageId : null,
            ])->save();
        }

        return $ok;
    }

    /**
     * Build the pre-send log row. Returns null if the activity_log subsystem
     * itself is broken — we never want logging failures to suppress sends.
     */
    private function writeLog(
        NotificationTemplate $template,
        NotificationContext $ctx,
        ?array $recipient,
        string $status,
        ?string $skipReason,
        ?string $idempotencyKey,
    ): ?NotificationLog {
        try {
            return NotificationLog::create([
                'template_key' => $template->key,
                'notification_template_id' => $template->id,
                'channel' => $template->channel,
                'recipient_masked' => $recipient !== null
                    ? NotificationLog::maskRecipient((string) $recipient['value'])
                    : null,
                'recipient_hash' => $recipient !== null
                    ? NotificationLog::hashRecipient((string) $recipient['value'])
                    : null,
                // Snapshot the (strategy, value) that's about to be tried so
                // Resend can reproduce it later. doDispatch mutates these on
                // a per-recipient template clone before calling deliver(),
                // so reading them off $template here captures the per-attempt
                // pair rather than the row's legacy default.
                'recipient_strategy' => $template->recipient_strategy,
                'recipient_value' => $template->recipient_value,
                'devotee_id' => $this->devoteeIdFromContext($ctx),
                'status' => $status,
                'skip_reason' => $skipReason,
                'attempts' => 0,
                'dispatched_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'context_snapshot' => $this->snapshotContext($ctx),
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification: failed to write audit log', [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Pre-resolve the recipient for logging purposes. Drivers re-resolve
     * for actual delivery; one extra lookup is cheap and keeps the log
     * accurate even when drivers differ in how they pick recipients.
     */
    private function resolveRecipientForLog(NotificationTemplate $template, NotificationContext $ctx): ?array
    {
        // Push has no email/phone recipient — log as devotee reference.
        if ($template->channel === NotificationTemplate::CHANNEL_PUSH) {
            $devotee = $ctx->get('devotee');
            if ($devotee instanceof Model) {
                return ['type' => 'devotee', 'value' => 'devotee:' . $devotee->getKey()];
            }
            return null;
        }

        $expectedType = $template->channel === NotificationTemplate::CHANNEL_EMAIL ? 'email' : 'phone';
        return $this->recipients->resolve($template, $ctx, $expectedType);
    }

    /**
     * Was this exact (key, channel) attempted within the dedup window?
     * Looks for a SENT or PENDING row — failed ones are eligible for
     * retry by design.
     */
    private function isDuplicate(string $idempotencyKey, string $channel): bool
    {
        return NotificationLog::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('channel', $channel)
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->where('created_at', '>=', now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS))
            ->exists();
    }

    /**
     * temple_devotees uses UUID primary keys (char(36)), so the log
     * column is a string. Casting to int here used to silently coerce
     * every UUID to 0 — return a string instead.
     */
    private function devoteeIdFromContext(NotificationContext $ctx): ?string
    {
        $devotee = $ctx->get('devotee');
        if ($devotee instanceof Devotee) {
            $key = $devotee->getKey();
            return $key === null ? null : (string) $key;
        }
        $id = $ctx->get('devotee.id');
        if ($id === null || $id === '') {
            return null;
        }
        return (string) $id;
    }

    /**
     * Reduce the dispatch context to a JSON-serialisable, size-bounded
     * snapshot for the audit row. Eloquent models are flattened to their
     * primary keys to avoid leaking attributes; private fields (PAN, etc.)
     * are explicitly filtered.
     */
    private function snapshotContext(NotificationContext $ctx): array
    {
        $blacklist = ['pan', 'password', 'access_token', 'token', '_attachments'];
        $out = [];

        foreach ($ctx->asArray() as $key => $value) {
            if (in_array($key, $blacklist, true)) {
                continue;
            }
            if ($value instanceof Model) {
                $out[$key] = [
                    'model' => get_class($value),
                    'id' => $value->getKey(),
                ];
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                // shallow clone, drop anything non-scalar to keep size bounded
                $out[$key] = array_filter(
                    $value,
                    fn ($v) => is_scalar($v) || $v === null,
                );
                continue;
            }
            // unknown object type — store the class name only
            $out[$key] = ['object' => get_class($value)];
        }

        // Hard cap at ~8KB serialised so a runaway context never blows the JSON column.
        $encoded = json_encode($out);
        if ($encoded !== false && strlen($encoded) > 8192) {
            return ['_truncated' => true, 'keys' => array_keys($out)];
        }
        return $out;
    }
}
