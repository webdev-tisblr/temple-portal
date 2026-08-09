<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\HasManagedImages;
use App\Services\SevaSlotService;
use App\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SevaBooking extends Model
{
    use HasManagedImages, HasUuid, LogsActivity;

    /**
     * Money-path audit (item 6.1). Status is the interesting column:
     * `mark_completed`, cancellations and payment capture all move it.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_amount', 'booking_date', 'slot_time', 'quantity', 'receipt_number'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('money');
    }

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
        'sankalp',
        'selected_product_id',
        'selected_variant_label',
        'notes',
        'cancelled_at',
        'cancellation_reason',
        'receipt_number',
        'receipt_path',
        'greeting_card_path',
    ];

    /** Cascade-delete the cached receipt PDF + greeting card with the row. */
    protected function managedImages(): array
    {
        return [
            'receipt_path' => 'r2_private',
            'greeting_card_path' => 'r2_private',
        ];
    }

    protected $casts = [
        'status' => BookingStatus::class,
        'booking_date' => 'date',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Human-readable slot_time — the ONLY safe way to display it.
     *
     * Time-slotted sevas store a real "HH:MM(:SS)", but full-day and
     * full-week sevas store the sentinel 'full_day' / 'full_week'
     * (see SevaSlotService), which Carbon::parse() throws an
     * InvalidFormatException on. The booking-success page parsed the raw
     * column and 500'd after a real, already-captured payment — never
     * parse slot_time directly, call this.
     *
     * Returns null when there's no slot at all, so callers keep their
     * own "hide the row" / "—" behaviour.
     */
    public function getSlotTimeLabelAttribute(): ?string
    {
        $slot = trim((string) ($this->slot_time ?? ''));

        if ($slot === '') {
            return null;
        }

        if ($slot === SevaSlotService::SLOT_TYPE_FULL_DAY) {
            return __('seva.slot_full_day');
        }

        if ($slot === SevaSlotService::SLOT_TYPE_FULL_WEEK) {
            return __('seva.slot_full_week');
        }

        // "07:00" / "07:00:00" → "07:00 AM".
        if (preg_match('/^(\d{1,2}):(\d{2})/', $slot, $m)
            && (int) $m[1] <= 23
            && (int) $m[2] <= 59
        ) {
            return Carbon::createFromTime((int) $m[1], (int) $m[2])->format('h:i A');
        }

        // Anything unexpected shows as-is rather than taking a page down.
        return $slot;
    }

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

    public function selectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'selected_product_id');
    }
}
