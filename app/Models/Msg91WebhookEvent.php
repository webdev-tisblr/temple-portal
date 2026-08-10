<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every inbound MSG91 SMS delivery report (DLR).
 *
 * Primary purpose: turn "no SMS arrived" into a diagnosis. MSG91 accepts
 * every submission with {"type":"success"} — including submissions with a
 * wrong auth key or an invalid template — so the send path can never tell
 * the trust whether a message was delivered. The delivery report can, and
 * carries MSG91's own wording for the failure ("Template ID Missing or
 * Invalid Template", "DND number", "Absent Subscriber"), which is stored
 * verbatim in `description`.
 *
 * Secondary purpose: idempotency. Msg91WebhookController inserts one row
 * per synthesised `event_key`; a retried POST is a no-op.
 *
 * @property string|null $request_id
 * @property string|null $recipient_masked
 * @property string|null $delivery_status
 * @property string|null $description
 */
class Msg91WebhookEvent extends Model
{
    protected $table = 'temple_msg91_webhook_events';

    protected $fillable = [
        'event_key',
        'request_id',
        'recipient_masked',
        'recipient_hash',
        'status_code',
        'provider_status',
        'description',
        'delivery_status',
        'reported_at',
        'payload',
        'notification_log_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'reported_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * Cache key for "has MSG91 ever sent us a delivery report?". Used by
     * the admin so an SMS sitting at 'submitted' reads as "delivery
     * reporting not configured yet" rather than as a system fault.
     */
    public const REPORTING_SEEN_CACHE_KEY = 'msg91_delivery_reporting_seen';

    public function notificationLog(): BelongsTo
    {
        return $this->belongsTo(NotificationLog::class, 'notification_log_id');
    }

    /**
     * Has MSG91 ever delivered a report to this install?
     *
     * Until the trust pastes the webhook URL into the MSG91 dashboard no
     * reports arrive at all, so EVERY SMS row sits unconfirmed forever.
     * That is a configuration state, not a failure, and the admin UI says
     * so — this is the flag it reads. Cached briefly because it is
     * consulted once per rendered log row.
     */
    public static function reportingConfigured(): bool
    {
        try {
            return (bool) cache()->remember(
                self::REPORTING_SEEN_CACHE_KEY,
                now()->addMinutes(5),
                fn (): bool => static::query()->exists(),
            );
        } catch (\Throwable) {
            // Never let a cache/DB hiccup break a table render.
            return false;
        }
    }

    /** Called by the controller after a successful insert. */
    public static function forgetReportingCache(): void
    {
        try {
            cache()->forget(self::REPORTING_SEEN_CACHE_KEY);
        } catch (\Throwable) {
            // no-op
        }
    }

    /**
     * Mask any phone-shaped value inside a raw MSG91 payload before it is
     * persisted.
     *
     * The project rule is absolute: a full mobile number is never written
     * to a log, a database column, or an admin screen. MSG91's delivery
     * report puts the number in `number` (and, depending on account and
     * route, in `mobile` / `msisdn` / `recipient` / `to`), so the raw JSON
     * we keep "so nothing is lost" would otherwise smuggle it back in.
     *
     * Only keys that name a phone are touched — request ids, template ids
     * and MSG91's own descriptions are preserved byte-for-byte.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function redactPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && self::isPhoneKey($key)
                    ? self::maskPhoneValue($item)
                    : self::redactPayload($item);
            }

            return $out;
        }

        return $value;
    }

    private static function isPhoneKey(string $key): bool
    {
        return (bool) preg_match('/(^to$|number|mobile|msisdn|recipient|phone)/i', $key);
    }

    /** @param  mixed  $value */
    private static function maskPhoneValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($v) => self::maskPhoneValue($v), $value);
        }
        if (is_string($value) || is_int($value)) {
            return \App\Services\SmsService::maskPhone((string) $value);
        }

        return $value;
    }

    /**
     * Stable join hash for a recipient. Built from the LAST 10 DIGITS so
     * "9876543210" (what the app stores) and "919876543210" (what MSG91
     * reports back) hash identically.
     */
    public static function hashRecipient(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            return null;
        }

        return hash('sha256', substr($digits, -10));
    }
}
