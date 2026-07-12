<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One materialised seva reminder: "send the {offset} reminder for this
 * booking at fire_at". Written by SevaReminderScheduler when a booking is
 * confirmed; consumed by DispatchSevaReminders on the cron tick.
 *
 * Status is the dedup + recovery mechanism: a reminder only fires while it
 * is `pending`; once dispatched it flips to `sent` and is never touched
 * again. A reminder whose fire_at passed while the cron was down stays
 * `pending`, so the next run still delivers it (just late).
 */
class SevaReminderSchedule extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $table = 'temple_seva_reminder_schedules';

    protected $fillable = [
        'seva_booking_id',
        'offset',
        'rule_id',
        'fire_at',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'fire_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(SevaBooking::class, 'seva_booking_id');
    }

    /** The reminder rule that produced this row; null for legacy offset rows. */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SevaReminderRule::class, 'rule_id');
    }
}
