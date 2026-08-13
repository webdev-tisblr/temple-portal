<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use App\Support\LocalizedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Hall extends Model
{
    use HasManagedImages;

    protected $table = 'temple_halls';

    protected static function booted(): void
    {
        // Bust the API halls list + home page hall card on any admin change.
        $bust = static function (): void {
            LocalizedCache::forget('halls.active');
            Cache::forget('home.hall.v1');
        };
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected $fillable = [
        'name',
        'name_gu',
        'name_hi',
        'name_en',
        'description',
        'description_gu',
        'description_hi',
        'description_en',
        'capacity',
        'max_booking_days',
        'booking_cutoff_hours',
        'day_start_time',
        'price_per_day',
        'price_per_half_day',
        'gst_rate',
        'amenities',
        'blackout_dates',
        'blackout_days',
        'rules',
        'rules_gu',
        'rules_hi',
        'rules_en',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'max_booking_days' => 'integer',
        'booking_cutoff_hours' => 'integer',
        'price_per_day' => 'decimal:2',
        'price_per_half_day' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'amenities' => 'array',
        'blackout_dates' => 'array',
        'blackout_days' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Admin blockout check: returns the reason string when the date is
     * blocked (mirrors SevaSlotService::getBlackoutReason), else null.
     * Two layers, specific date first:
     *   blackout_dates — [{date: 'YYYY-MM-DD', reason: string}]
     *   blackout_days  — recurring weekly closures, list of lowercase
     *                    weekday names ('monday'…'sunday')
     */
    public function blackoutReason(string $date): ?string
    {
        foreach ((array) $this->blackout_dates as $entry) {
            if (($entry['date'] ?? null) === $date) {
                return $entry['reason'] ?? '';
            }
        }

        $days = array_map('strtolower', (array) $this->blackout_days);
        if ($days !== []) {
            $weekday = strtolower(Carbon::parse($date)->format('l'));
            if (in_array($weekday, $days, true)) {
                return __('halls.weekday_blocked');
            }
        }

        return null;
    }

    /**
     * How many consecutive days this hall may be booked in one go.
     * Defaults to 1 — multi-day is opt-in per hall (item 4.2), so an
     * un-migrated / untouched hall behaves exactly as it did before.
     */
    public function maxBookingDays(): int
    {
        return max(1, (int) ($this->max_booking_days ?? 1));
    }

    /**
     * Admin-configured cut-off in hours (item 4.3). Falls back to the
     * temple-wide `hall_default_cutoff_hours` system setting, then 0
     * (no cut-off = today's behaviour).
     */
    public function bookingCutoffHours(): int
    {
        $own = $this->booking_cutoff_hours;
        if ($own !== null && (int) $own > 0) {
            return (int) $own;
        }

        return max(0, (int) SystemSetting::getValue('hall_default_cutoff_hours', '0'));
    }

    /**
     * The moment a booking on $date is considered to start, used as the
     * anchor the cut-off counts back from. Halls have no slot times, so
     * the admin sets a per-hall "day starts at" time (default 09:00).
     */
    public function dayStartMoment(string $date): Carbon
    {
        $raw = (string) ($this->day_start_time ?? '09:00');
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
            $m = [null, '9', '00'];
        }

        return Carbon::parse($date)->setTime((int) $m[1], (int) $m[2], 0);
    }

    /**
     * Locale-aware name. Falls back to Gujarati (the primary language),
     * then to the legacy single-column `name` for safety.
     */
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";

        return $this->attributes[$field]
            ?? $this->attributes['name_gu']
            ?? $this->attributes['name']
            ?? null;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "description_{$locale}";

        return $this->attributes[$field]
            ?? $this->attributes['description_gu']
            ?? $this->attributes['description']
            ?? null;
    }

    public function getRulesAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "rules_{$locale}";

        return $this->attributes[$field]
            ?? $this->attributes['rules_gu']
            ?? $this->attributes['rules']
            ?? null;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class, 'hall_id');
    }

    /**
     * Reminder rules for this hall. Ordered the way the admin repeater shows
     * them so the list does not reshuffle between saves.
     */
    public function reminderRules(): HasMany
    {
        return $this->hasMany(HallReminderRule::class, 'hall_id')
            ->orderBy('sort_order')
            ->orderBy('offset_minutes');
    }

    public function media(): HasMany
    {
        return $this->hasMany(HallMedia::class, 'hall_id')->orderBy('sort_order')->orderBy('id');
    }
}
