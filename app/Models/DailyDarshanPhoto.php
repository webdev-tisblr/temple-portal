<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;

class DailyDarshanPhoto extends Model
{
    use HasManagedImages;

    protected $table = 'temple_daily_darshan_photos';

    protected static function booted(): void
    {
        // Bust the darshan page's daily photo cache on any admin change.
        $bust = fn () => \Illuminate\Support\Facades\Cache::forget('darshan_page_daily_photo');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

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
