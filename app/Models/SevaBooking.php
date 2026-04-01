<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SevaBooking extends Model
{
    use HasUuid;

    protected $table = 'temple_seva_bookings';

    protected $fillable = [
        'devotee_id',
        'seva_id',
        'booking_date',
        'slot_time',
        'quantity',
        'total_amount',
        'status',
        'payment_id',
        'devotee_name_for_seva',
        'gotra',
        'sankalp',
        'notes',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => BookingStatus::class,
        'booking_date' => 'date',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function seva(): BelongsTo
    {
        return $this->belongsTo(Seva::class, 'seva_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
