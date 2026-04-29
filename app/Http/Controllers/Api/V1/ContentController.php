<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Announcement;
use App\Models\BlogPost;
use App\Models\ContactSubmission;
use App\Models\DailyDarshanPhoto;
use App\Models\DarshanTiming;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ContentController extends BaseApiController
{
    /**
     * Return active, non-expired announcements.
     * Cached for 15 minutes.
     */
    public function announcements(): JsonResponse
    {
        $announcements = Cache::remember('announcements.active.v2', 900, function () {
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
                    'image_url' => $announcement->image_path
                        ? asset('storage/' . $announcement->image_path)
                        : null,
                    'is_urgent' => $announcement->is_urgent,
                    'published_at' => $announcement->published_at?->toISOString(),
                    'expires_at' => $announcement->expires_at?->toISOString(),
                ]);
        });

        return $this->success($announcements);
    }

    /**
     * Return the latest active daily darshan photo for sharing in the app.
     */
    public function dailyDarshanPhoto(): JsonResponse
    {
        $photo = DailyDarshanPhoto::query()
            ->where('is_active', true)
            ->orderByDesc('captured_on')
            ->orderByDesc('id')
            ->first();

        if (!$photo) {
            return $this->success(null);
        }

        return $this->success([
            'id' => $photo->id,
            'image_url' => $photo->image_path
                ? asset('storage/' . $photo->image_path)
                : null,
            'caption' => $photo->caption,
            'caption_gu' => $photo->caption_gu,
            'caption_hi' => $photo->caption_hi,
            'caption_en' => $photo->caption_en,
            'captured_on' => $photo->captured_on?->toDateString(),
        ]);
    }

    /**
     * Return live darshan stream configuration from system settings.
     */
    public function liveDarshan(): JsonResponse
    {
        $streamUrl = SystemSetting::getValue('live_darshan_stream_url', '')
            ?: SystemSetting::getValue('youtube_live_url', '');
        $isLive = SystemSetting::getValue('live_darshan_is_live', '0');
        $channelId = SystemSetting::getValue('live_darshan_channel_id', '')
            ?: SystemSetting::getValue('youtube_channel_id', '');

        // If stream URL exists, consider it live
        if (empty($isLive) || $isLive === '0') {
            $isLive = !empty($streamUrl) ? '1' : '0';
        }

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

        // Hide events that ended more than 1 day ago; order upcoming first.
        $cutoff = now()->subDay()->toDateString();

        $query = Event::query()
            ->where('status', 'published')
            ->where(function ($q) use ($cutoff) {
                // Event has no end_date AND starts today or later
                $q->where(function ($qq) use ($cutoff) {
                    $qq->whereNull('end_date')
                        ->where('start_date', '>=', $cutoff);
                })
                // OR event has an end_date still today or later
                ->orWhere('end_date', '>=', $cutoff);
            })
            ->orderByDesc('is_featured')
            ->orderBy('start_date');

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
     * Return published blog posts.
     */
    public function blog(Request $request): JsonResponse
    {
        $query = BlogPost::where('status', 'published')
            ->orderByDesc('published_at');

        if ($request->query('category')) {
            $query->where('category', $request->query('category'));
        }

        $posts = $query->paginate(20);

        $data = $posts->getCollection()->map(fn (BlogPost $post) => [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'title_gu' => $post->title_gu,
            'title_hi' => $post->title_hi,
            'title_en' => $post->title_en,
            'excerpt' => $post->excerpt_gu,
            'featured_image_url' => $post->featured_image_path ? asset('storage/' . $post->featured_image_path) : null,
            'category' => $post->category,
            'published_at' => $post->published_at?->toISOString(),
        ]);

        return $this->success([
            'posts' => $data,
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Return a single blog post by slug.
     */
    public function blogDetail(string $slug): JsonResponse
    {
        $post = BlogPost::where('slug', $slug)->where('status', 'published')->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        return $this->success([
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'title_gu' => $post->title_gu,
            'title_hi' => $post->title_hi,
            'title_en' => $post->title_en,
            // HTML-stripped plain text for mobile; preserves paragraph/line breaks.
            'body' => $this->htmlToPlainText($post->body),
            'body_gu' => $this->htmlToPlainText($post->body_gu),
            'body_hi' => $this->htmlToPlainText($post->body_hi),
            'body_en' => $this->htmlToPlainText($post->body_en),
            // Raw HTML fields for web/clients that render rich text.
            'body_html' => $post->body,
            'body_html_gu' => $post->body_gu,
            'body_html_hi' => $post->body_hi,
            'body_html_en' => $post->body_en,
            'featured_image_url' => $post->featured_image_path ? asset('storage/' . $post->featured_image_path) : null,
            'category' => $post->category,
            'published_at' => $post->published_at?->toISOString(),
        ]);
    }

    /**
     * Convert RichEditor HTML to readable plain text, preserving paragraph breaks.
     */
    private function htmlToPlainText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        // Convert block-level closings and <br> to newlines so paragraphs stay separated.
        $normalised = preg_replace(
            ['#</(p|div|h[1-6]|li|blockquote)>#i', '#<br\s*/?>#i'],
            ["\n\n", "\n"],
            $html
        );

        // Strip remaining tags.
        $plain = strip_tags($normalised);

        // Decode entities (&nbsp;, &amp;, Gujarati numeric entities, etc.).
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse excess blank lines and trim.
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain);

        return trim($plain);
    }

    /**
     * Handle contact form submission.
     */
    public function submitContact(Request $request): JsonResponse
    {
        $key = 'contact:' . ($request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return $this->error('ઘણા બધા પ્રયાસો. કૃપા કરી થોડા સમય પછી ફરી પ્રયાસ કરો.', 429);
        }
        RateLimiter::hit($key, 3600);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:15',
            'email' => 'nullable|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
        ]);

        ContactSubmission::create(array_merge($validated, [
            'ip_address' => $request->ip(),
            'is_read' => false,
        ]));

        return $this->success(null, 'તમારો સંદેશ સફળતાપૂર્વક મોકલાયો.');
    }

    /**
     * Return temple info (name, contact, address, about, rules, nearby).
     * Draws from SystemSetting so admin can edit without a code change.
     */
    public function templeInfo(): JsonResponse
    {
        $info = Cache::remember('content.temple_info.v1', 1800, function () {
            return [
                'name' => SystemSetting::getValue('trust_name', 'શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ'),
                'phone' => SystemSetting::getValue('trust_phone'),
                'email' => SystemSetting::getValue('trust_email'),
                'address' => SystemSetting::getValue('trust_address'),
                'about' => SystemSetting::getValue('trust_about'),
                'rules' => SystemSetting::getValue('trust_rules'),
                'nearby' => SystemSetting::getValue('trust_nearby'),
                'facilities' => SystemSetting::getValue('trust_facilities'),
                'map_url' => SystemSetting::getValue('trust_map_url'),
                'youtube_channel_url' => SystemSetting::getValue('youtube_channel_url'),
                'whatsapp_number' => SystemSetting::getValue('trust_whatsapp'),
            ];
        });

        return $this->success($info);
    }

    /**
     * Return active donation types.
     */
    public function donationTypes(): JsonResponse
    {
        $types = Cache::remember('donation_types.active', 1800, function () {
            return DonationType::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (DonationType $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'name_gu' => $t->name_gu,
                    'name_hi' => $t->name_hi,
                    'name_en' => $t->name_en,
                    'slug' => $t->slug,
                    'description' => $t->description,
                    'extra_fields' => $t->extra_fields,
                ]);
        });

        return $this->success($types);
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
            'slug' => $campaign->slug,
            'title' => $campaign->title,
            'description' => $campaign->description,
            'writeup' => $campaign->writeup,
            'goal_amount' => (float) $campaign->goal_amount,
            'raised_amount' => (float) $campaign->raised_amount,
            'donor_count' => $campaign->donor_count,
            'image_url' => $campaign->image_path
                ? asset('storage/' . $campaign->image_path)
                : null,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'is_featured' => (bool) $campaign->is_featured,
            'progress_percent' => $campaign->goal_amount > 0
                ? round(((float) $campaign->raised_amount / (float) $campaign->goal_amount) * 100, 2)
                : 0,
        ];
    }
}
