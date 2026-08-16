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

    /**
     * The photo to show as "today's darshan": today's most recent upload,
     * falling back to the newest photo on record so a page that features
     * darshan is never blank on a day nobody uploaded.
     *
     * Shares the `darshan_page_daily_photo` cache key (and therefore the
     * booted() bust above) with the darshan page itself, so the login page
     * and /darshan can never disagree about which photo is current.
     */
    public static function currentCached(): ?self
    {
        return Cache::remember('darshan_page_daily_photo', 600, function () {
            return static::where('is_active', true)
                ->whereDate('captured_on', today())
                ->latest('id')
                ->first()
                ?? static::where('is_active', true)
                    ->orderByDesc('captured_on')
                    ->orderByDesc('id')
                    ->first();
        });
    }

    /**
     * The darshan photo that belongs to a GIVEN DAY: that day's most recent
     * upload, else the last one uploaded before it, else the newest on record.
     *
     * currentCached() answers "what is today's darshan" for pages, and is
     * cached under one shared key. This answers "what was the darshan on
     * $date" for artefacts that are DATED — a greeting card carries the day
     * the donation was made or the seva was performed, and r2_private is a
     * regenerable cache, so a card rebuilt weeks later after a sweep must
     * come back with the same photo it was delivered with. Anchoring on the
     * date rather than on `today()` is what makes that reproducible.
     *
     * Deliberately uncached: the keys would be per-date and the booted()
     * bust above only clears the single page key, so a stale entry could
     * outlive the admin edit that should have replaced it. One indexed query
     * per card generation is not worth that risk.
     */
    public static function forDate(?\DateTimeInterface $date = null): ?self
    {
        $date ??= today();

        return static::where('is_active', true)
            ->whereDate('captured_on', $date)
            ->latest('id')
            ->first()
            ?? static::where('is_active', true)
                ->whereDate('captured_on', '<=', $date)
                ->orderByDesc('captured_on')
                ->orderByDesc('id')
                ->first()
            // Nothing on or before that day — a card for a date that predates
            // every upload still shows Hanumanji rather than nothing.
            ?? static::where('is_active', true)
                ->orderByDesc('captured_on')
                ->orderByDesc('id')
                ->first();
    }

    /**
     * The R2 key to composite this photo from: the `medium` derivative, since
     * originals come straight off a phone at 8–12 MB and GD would decode the
     * whole thing to composite a 600px box.
     */
    public function overlaySourcePath(): ?string
    {
        return $this->medium_path ?: $this->image_path;
    }

    /**
     * Best URL for displaying this photo at page scale: the `medium`
     * derivative, because some originals are 8–12 MB straight off a phone.
     * Falls back to the original when the derivative hasn't been generated
     * (older rows predate HasImageDerivatives).
     */
    public function displayUrl(): ?string
    {
        return image_url($this->medium_path ?: $this->image_path);
    }

    public function getCaptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "caption_{$locale}";

        return $this->$field ?? $this->caption_gu;
    }
}
