<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of every inbound Razorpay webhook delivery.
 *
 * Primary purpose: durable idempotency. PaymentWebhookController inserts
 * one row per event_id; the unique index on event_id guarantees a
 * duplicate delivery surfaces as an integrity violation that we
 * translate into a 'duplicate' outcome without re-processing.
 *
 * Secondary purpose: forensic trail. If a captured payment's status
 * looks wrong, support can grep this table by razorpay_order_id and
 * see every event Razorpay sent for that order — including signature-
 * verified events the system intentionally ignored (unknown
 * event_type, payment row missing, etc).
 */
class RazorpayWebhookEvent extends Model
{
    public const OUTCOME_PROCESSED = 'processed';
    public const OUTCOME_IGNORED = 'ignored';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_ERROR = 'error';

    protected $table = 'temple_razorpay_webhook_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'razorpay_order_id',
        'razorpay_payment_id',
        'payload',
        'outcome',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
