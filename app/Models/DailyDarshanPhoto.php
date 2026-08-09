<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\NotifyBookingDayDevoteesOfDarshanPhoto;
use App\Models\Concerns\HasImageDerivatives;
use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DailyDarshanPhoto extends Model
{
    use HasImageDerivatives, HasManagedImages;

    protected $table = 'temple_daily_darshan_photos';

    protected static function booted(): void
    {
        // Bust the darshan page's daily photo cache on any admin change.
        $bust = fn () => Cache::forget('darshan_page_daily_photo');
        static::saved($bust);
        static::deleted($bust);

        // Booking-day darshan delivery — only for the day's FIRST active
        // photo (5 uploads must not mean 5 messages) and only for a
        // current date (±1 day), so backfilling an old gallery can never
        // blast historic bookings. Photo-created-inactive-then-activated
        // misses this hook by design — the Edit page has a manual
        // "Send booking-day notifications" action for that case.
        static::created(function (self $photo) {
            if (! $photo->is_active || $photo->captured_on === null) {
                return;
            }
            if ($photo->captured_on->diffInDays(now()->startOfDay(), absolute: true) > 1) {
                return;
            }
            $isFirstOfDay = ! static::query()
                ->whereDate('captured_on', $photo->captured_on)
                ->where('id', '!=', $photo->id)
                ->exists();
            if ($isFirstOfDay) {
                NotifyBookingDayDevoteesOfDarshanPhoto::dispatch($photo->id);
            }
        });
    }

    protected function managedImages(): array
    {
        return [
            'image_path' => 'r2',
            'thumbnail_path' => 'r2',
            'medium_path' => 'r2',
        ];
    }

    /** @see HasImageDerivatives */
    protected function imageDerivatives(): array
    {
        return [
            'image_path' => [
                'thumbnail' => 'thumbnail_path',
                'medium' => 'medium_path',
            ],
        ];
    }

    protected $fillable = [
        'image_path',
        'thumbnail_path',
        'medium_path',
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
