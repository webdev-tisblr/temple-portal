<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyDarshanPhoto extends Model
{
    protected $table = 'temple_daily_darshan_photos';

    protected $fillable = [
        'image_path',
        'caption_gu',
        'caption_hi',
        'caption_en',
        'captured_on',
        'is_active',
    ];

    protected $casts = [
        'captured_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function getCaptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "caption_{$locale}";
        return $this->$field ?? $this->caption_gu;
    }
}
