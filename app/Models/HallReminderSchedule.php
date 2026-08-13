<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One pending reminder for one hall booking. */
class HallReminderSchedule extends Model
{
    protected $table = 'temple_hall_reminder_schedules';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hall_booking_id',
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
        return $this->belongsTo(HallBooking::class, 'hall_booking_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(HallReminderRule::class, 'rule_id');
    }
}
