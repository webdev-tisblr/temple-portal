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
}
