<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Announcement;
use App\Models\DonationCampaign;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
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
