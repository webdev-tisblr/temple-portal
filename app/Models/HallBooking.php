<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallBooking extends Model
{
    protected $table = 'temple_hall_bookings';

    protected $fillable = [
        'devotee_id',
        'hall_id',
        'booking_date',
        'booking_type',
        'purpose',
        'expected_guests',
        'contact_name',
        'contact_phone',
        'total_amount',
        'status',
        'payment_id',
        'admin_notes',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'total_amount' => 'decimal:2',
        'expected_guests' => 'integer',
    ];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class, 'hall_id');
    }
}
