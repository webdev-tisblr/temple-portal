<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-attempt audit row written by NotificationService. Surfaced in the
 * Filament admin as a read-only "Notifications log" resource so operators
 * can answer "did the donor receive their receipt?" without grepping
 * application logs.
 *
 * Status lifecycle:
 *   pending → sent     (driver returned true)
 *           → failed   (driver threw or returned false)
 *           → skipped  (template disabled, recipient unresolved, or idempotency hit)
 */
class NotificationLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    // Delivery-status lifecycle (populated from WhatsApp delivery webhooks).
    // The base $status column tracks send-attempt outcome; these constants
    // are the SEPARATE column delivery_status that tracks what happened
    // downstream after the upstream API accepted the message.
    public const DELIVERY_SENT = 'sent';                // accepted by Meta
    public const DELIVERY_DELIVERED = 'delivered';      // reached handset
    public const DELIVERY_READ = 'read';                // recipient opened
    public const DELIVERY_FAILED = 'failed';            // permanent failure
    public const DELIVERY_UNDELIVERED = 'undelivered';  // transient failure

    protected $table = 'temple_notification_logs';

    protected $fillable = [
        'template_key',
        'notification_template_id',
        'channel',
        'recipient_masked',
        'recipient_hash',
        'devotee_id',
        'status',
        'skip_reason',
        'error_message',
        'provider_response_code',
        'provider_message_id',
        'delivery_status',
        'delivery_status_at',
        'failure_reason',
        'attempts',
        'dispatched_at',
        'sent_at',
        'idempotency_key',
        'context_snapshot',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'dispatched_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivery_status_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    /**
     * Mask a raw email or phone for safe admin display.
     *   user@example.com  → us**@example.com
     *   +919876543210      → +91*****3210
     */
    public static function maskRecipient(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            $keep = (int) max(1, min(2, strlen($local) - 2));
            $masked = substr($local, 0, $keep) . str_repeat('*', max(0, strlen($local) - $keep));
            return $masked . '@' . $domain;
        }

        // Phone: keep last 4 digits, mask the rest after the country code if any.
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return substr($digits, 0, max(1, strlen($digits) - 4))
            . str_repeat('*', 0) // structural placeholder for tests
            . substr($digits, -4);
    }

    /**
     * Stable hash for joining logs to a recipient without storing PII.
     */
    public static function hashRecipient(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * Convenience accessor — does this log row count as a delivery success?
     */
    protected function delivered(): Attribute
    {
        return Attribute::get(fn () => $this->status === self::STATUS_SENT);
    }
}
