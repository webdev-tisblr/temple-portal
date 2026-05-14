<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of every inbound WhatsApp webhook delivery from Meta Cloud
 * API (relayed by "The Internet Store" BSP, but using Meta's verbatim
 * payload format).
 *
 * Primary purpose: durable forensic trail. When a devotee says "I never
 * got the receipt" we look up the original notification_log row, grab
 * its provider_message_id, then grep this table for every status event
 * that came back for that message — sent / delivered / failed with code.
 *
 * Secondary purpose: idempotency. WhatsAppWebhookController inserts
 * one row per (message_id, status); the composite unique key
 * `wa_webhook_event_dedup` makes duplicate deliveries (retry queue
 * triggered by a slow 200 from our side) collapse silently.
 */
class WhatsAppWebhookEvent extends Model
{
    public const KIND_MESSAGE_STATUS = 'message_status';
    public const KIND_INBOUND_MESSAGE = 'inbound_message';
    public const KIND_TEMPLATE_STATUS = 'template_status';
    public const KIND_ACCOUNT_UPDATE = 'account_update';
    public const KIND_PHONE_UPDATE = 'phone_update';
    public const KIND_UNKNOWN = 'unknown';

    protected $table = 'temple_whatsapp_webhook_events';

    protected $fillable = [
        'event_kind',
        'message_id',
        'status',
        'recipient_id',
        'error_code',
        'error_message',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'error_code' => 'integer',
    ];
}
