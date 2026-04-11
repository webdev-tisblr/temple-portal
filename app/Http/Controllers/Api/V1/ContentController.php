<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Announcement;
use App\Models\DarshanTiming;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ContentController extends BaseApiController
{
    /**
     * Return active, non-expired announcements.
     * Cached for 15 minutes.
     */
    public function announcements(): JsonResponse
    {
        $announcements = Cache::remember('announcements.active', 900, function () {
            return Announcement::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('is_urgent')
                ->orderByDesc('published_at')
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'image_path' => $announcement->image_path,
                    'is_urgent' => $announcement->is_urgent,
                    'published_at' => $announcement->published_at?->toISOString(),
                    'expires_at' => $announcement->expires_at?->toISOString(),
                ]);
        });

        return $this->success($announcements);
    }

    /**
     * Return live darshan stream configuration from system settings.
     */
    public function liveDarshan(): JsonResponse
    {
        $streamUrl = SystemSetting::getValue('live_darshan_stream_url', '');
        $isLive = SystemSetting::getValue('live_darshan_is_live', '0');
        $channelId = SystemSetting::getValue('live_darshan_channel_id', '');

        return $this->success([
            'stream_url' => $streamUrl,
            'is_live' => (bool) (int) $isLive,
            'channel_id' => $channelId,
        ]);
    }

    /**
     * Return all active donation campaigns.
     */
    public function campaigns(): JsonResponse
    {
        $campaigns = DonationCampaign::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DonationCampaign $campaign) => $this->mapCampaign($campaign));

        return $this->success($campaigns);
    }

    /**
     * Return a single campaign by its model binding.
     */
    public function campaignDetail(DonationCampaign $campaign): JsonResponse
    {
        if (!$campaign->is_active) {
            return $this->error('Campaign not found', 404);
        }

        return $this->success($this->mapCampaign($campaign));
    }

    /**
     * Return current darshan timings.
     * Cached for 30 minutes.
     */
    public function darshanTimings(): JsonResponse
    {
        $timings = Cache::remember('darshan_timings.active', 1800, function () {
            return DarshanTiming::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('effective_from')
                        ->orWhere('effective_from', '<=', now()->toDateString());
                })
                ->where(function ($q) {
                    $q->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', now()->toDateString());
                })
                ->orderByRaw("FIELD(day_type, 'regular', 'sunday', 'festival', 'special')")
                ->get()
                ->map(fn (DarshanTiming $timing) => [
                    'id' => $timing->id,
                    'day_type' => $timing->day_type,
                    'label' => $timing->label,
                    'morning_open' => $timing->morning_open,
                    'morning_close' => $timing->morning_close,
                    'afternoon_open' => $timing->afternoon_open,
                    'afternoon_close' => $timing->afternoon_close,
                    'evening_open' => $timing->evening_open,
                    'evening_close' => $timing->evening_close,
                    'aarti_morning' => $timing->aarti_morning,
                    'aarti_evening' => $timing->aarti_evening,
                    'special_note' => $timing->{'special_note_' . app()->getLocale()} ?? $timing->special_note_gu,
                ]);
        });

        return $this->success($timings);
    }

    /**
     * Return gallery images, optionally filtered by category.
     * Cached for 15 minutes per category.
     */
    public function gallery(Request $request): JsonResponse
    {
        $category = $request->query('category');

        $cacheKey = $category ? "gallery.{$category}" : 'gallery.all';

        $images = Cache::remember($cacheKey, 900, function () use ($category) {
            $query = GalleryImage::query()->orderBy('sort_order');

            if ($category) {
                $query->where('category', $category);
            }

            return $query->get()->map(fn (GalleryImage $image) => [
                'id' => $image->id,
                'title' => $image->title,
                'description' => $image->description,
                'image_url' => $image->image_path ? asset('storage/' . $image->image_path) : null,
                'thumbnail_url' => $image->thumbnail_path ? asset('storage/' . $image->thumbnail_path) : null,
                'medium_url' => $image->medium_path ? asset('storage/' . $image->medium_path) : null,
                'category' => $image->category,
                'is_wallpaper' => $image->is_wallpaper,
            ]);
        });

        return $this->success($images);
    }

    /**
     * Return published events, optionally filtered by type.
     */
    public function events(Request $request): JsonResponse
    {
        $type = $request->query('type');

        $query = Event::query()
            ->where('status', 'published')
            ->orderByDesc('start_date');

        if ($type) {
            $query->where('event_type', $type);
        }

        $events = $query->get()->map(fn (Event $event) => [
            'id' => $event->id,
            'title' => $event->title,
            'title_gu' => $event->title_gu,
            'title_hi' => $event->title_hi,
            'title_en' => $event->title_en,
            'description' => $event->description,
            'description_gu' => $event->description_gu,
            'description_hi' => $event->description_hi,
            'description_en' => $event->description_en,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'location' => $event->location,
            'image_url' => $event->image_path ? asset('storage/' . $event->image_path) : null,
            'is_featured' => $event->is_featured,
            'event_type' => $event->event_type?->value,
        ]);

        return $this->success($events);
    }

    /**
     * Map a campaign model to an API-safe array.
     *
     * @return array<string, mixed>
     */
    private function mapCampaign(DonationCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'description' => $campaign->description,
            'goal_amount' => (float) $campaign->goal_amount,
            'raised_amount' => (float) $campaign->raised_amount,
            'donor_count' => $campaign->donor_count,
            'image_path' => $campaign->image_path,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'progress_percent' => $campaign->goal_amount > 0
                ? round(((float) $campaign->raised_amount / (float) $campaign->goal_amount) * 100, 2)
                : 0,
        ];
    }
}
