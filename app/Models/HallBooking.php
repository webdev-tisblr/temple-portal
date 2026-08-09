<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HallBooking extends Model
{
    use HasManagedImages, LogsActivity;

    /** Money-path audit (item 6.1). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_amount', 'booking_date', 'end_date', 'days_count'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('money');
    }

    protected $table = 'temple_hall_bookings';

    protected function managedImages(): array
    {
        return ['invoice_path' => 'r2_private'];
    }

    protected $fillable = [
        'devotee_id',
        'hall_id',
        // booking_date is the RANGE START (item 4.2, 2026-08-09). It keeps
        // its name because live WhatsApp templates, the invoice blade and
        // the shipped app all bind to it.
        'booking_date',
        'end_date',
        'days_count',
        'booking_type',
        'purpose',
        'expected_guests',
        'contact_name',
        'contact_phone',
        'total_amount',
        'status',
        'payment_id',
        'admin_notes',
        'invoice_path',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'end_date' => 'date',
        'days_count' => 'integer',
        'total_amount' => 'decimal:2',
        'expected_guests' => 'integer',
    ];

    /**
     * Statuses that occupy the hall. `completed` is included (it was NOT
     * before 2026-08-09) so hall and seva agree — a completed booking must
     * still block its dates, which matters now that admin manual entries
     * and multi-day ranges exist.
     *
     * @var list<string>
     */
    public const OCCUPYING_STATUSES = ['pending', 'confirmed', 'completed'];

    /** Range end, tolerating pre-migration rows where end_date is null. */
    public function rangeEnd(): \Illuminate\Support\Carbon
    {
        return ($this->end_date ?? $this->booking_date)->copy();
    }

    public function getIsMultiDayAttribute(): bool
    {
        return $this->rangeEnd()->toDateString() !== $this->booking_date?->toDateString();
    }

    /** "12 Aug 2026" for a single day, "12 – 14 Aug 2026" for a range. */
    public function getDateRangeLabelAttribute(): string
    {
        if (! $this->booking_date) {
            return '';
        }

        $start = $this->booking_date;
        $end = $this->rangeEnd();

        if ($start->toDateString() === $end->toDateString()) {
            return $start->format('d M Y');
        }

        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('d').' – '.$end->format('d M Y');
        }

        return $start->format('d M Y').' – '.$end->format('d M Y');
    }

    /**
     * Bookings whose [booking_date, end_date] window overlaps [$start, $end].
     * Closed-interval overlap: existing.start <= req.end AND existing.end >= req.start.
     * COALESCE keeps pre-migration rows (null end_date) correct.
     */
    public function scopeOverlapping(\Illuminate\Database\Eloquent\Builder $query, string $start, string $end): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereDate('booking_date', '<=', $end)
            ->whereRaw('COALESCE(end_date, booking_date) >= ?', [$start]);
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class, 'hall_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
