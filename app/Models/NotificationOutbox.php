<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One queued-notification intent, written inside the caller's DB
 * transaction (see the migration and NotificationService::dispatch()).
 * Deleted after the send is processed; NotificationLog is the audit.
 */
class NotificationOutbox extends Model
{
    protected $table = 'temple_notification_outbox';

    protected $fillable = [
        'key',
        'context_snapshot',
        'idempotency_key',
        'only_channels',
        'queue',
        'claimed_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'only_channels' => 'array',
        'claimed_at' => 'datetime',
    ];
}
