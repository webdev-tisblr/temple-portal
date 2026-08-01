<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DarshanTiming extends Model
{
    protected $table = 'temple_darshan_timings';

    protected $fillable = [
        'day_type',
        'label',
        'label_gu',
        'label_hi',
        'label_en',
        'morning_open',
        'morning_close',
        'afternoon_open',
        'afternoon_close',
        'evening_open',
        'evening_close',
        'aarti_morning',
        'aarti_evening',
        'special_note_gu',
        'special_note_hi',
        'special_note_en',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Locale-aware label. Falls back to Gujarati, then the legacy `label`.
     */
    public function getLabelAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "label_{$locale}";
        return $this->attributes[$field]
            ?? $this->attributes['label_gu']
            ?? $this->attributes['label']
            ?? null;
    }

    /**
     * Is darshan open right now, and if not, when does it next open?
     *
     * Times are stored as IST wall-clock strings while the app runs in UTC
     * (prod APP_TIMEZONE=UTC), so ALL comparisons happen in Asia/Kolkata
     * explicitly. Saturday uses the `special` row (falling back to
     * `regular`); every other day uses `regular`.
     *
     * @return array{is_open: bool, next_opening: ?\Carbon\Carbon}
     */
    public static function scheduleNow(): array
    {
        $now = now()->setTimezone('Asia/Kolkata');

        $rows = static::query()
            ->where('is_active', true)
            ->whereIn('day_type', ['regular', 'special'])
            ->get()
            ->keyBy('day_type');

        $rowFor = function (\Carbon\Carbon $day) use ($rows): ?self {
            $type = $day->isSaturday() ? 'special' : 'regular';
            return $rows->get($type) ?? $rows->get('regular');
        };

        $windowsFor = function (?self $row, \Carbon\Carbon $day): array {
            if (! $row) {
                return [];
            }
            $windows = [];
            foreach ([['morning_open', 'morning_close'], ['afternoon_open', 'afternoon_close'], ['evening_open', 'evening_close']] as [$openCol, $closeCol]) {
                $open = $row->getAttributes()[$openCol] ?? null;
                $close = $row->getAttributes()[$closeCol] ?? null;
                if ($open && $close) {
                    $openAt = $day->copy()->setTimeFromTimeString($open);
                    $closeAt = $day->copy()->setTimeFromTimeString($close);
                    // A close at/before the open ("15:00–00:00") means the
                    // window crosses midnight. Without rolling the close to
                    // the next day, Carbon::between() silently swaps the
                    // inverted endpoints and 15:00–00:00 matched the whole
                    // MORNING instead (2026-08-01: live showed at 13:31 on
                    // a till-13:00 Saturday).
                    if ($closeAt->lessThanOrEqualTo($openAt)) {
                        $closeAt->addDay();
                    }
                    $windows[] = [$openAt, $closeAt];
                }
            }
            return $windows;
        };

        // Open right now?
        foreach ($windowsFor($rowFor($now), $now) as [$open, $close]) {
            if ($now->between($open, $close)) {
                return ['is_open' => true, 'next_opening' => null];
            }
        }

        // Next opening: rest of today, then up to a week ahead.
        for ($i = 0; $i <= 7; $i++) {
            $day = $now->copy()->startOfDay()->addDays($i);
            foreach ($windowsFor($rowFor($day), $day) as [$open, $close]) {
                if ($open->greaterThan($now)) {
                    return ['is_open' => false, 'next_opening' => $open];
                }
            }
        }

        return ['is_open' => false, 'next_opening' => null];
    }
}
