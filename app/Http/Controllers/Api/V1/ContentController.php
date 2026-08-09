<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ContactSubmission;
use App\Models\DailyDarshanPhoto;
use App\Models\DarshanTiming;
use App\Models\Devotee;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\SystemSetting;
use App\Models\Trustee;
use App\Services\DarshanShareCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ContentController extends BaseApiController
{
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
                ? image_url($photo->image_path)
                : null,
            // Additive: the shipped app reads `image_url` only, so it is
            // untouched. New builds should decode `medium_url` for the card
            // and keep `image_url` for the zoomable full-screen view.
            'thumbnail_url' => $photo->thumbnail_path ? image_url($photo->thumbnail_path) : null,
            'medium_url' => $photo->medium_path ? image_url($photo->medium_path) : null,
            'caption' => $photo->caption,
            'caption_gu' => $photo->caption_gu,
            'caption_hi' => $photo->caption_hi,
            'caption_en' => $photo->caption_en,
            'captured_on' => $photo->captured_on?->toDateString(),
        ]);
    }

    /**
     * Build (or fetch from cache) a personalised share card image for
     * today's darshan photo. The card is uploaded to R2 once per
     * (photo × devotee × format) and the URL is returned for the client
     * to share via WhatsApp / Instagram / etc.
     *
     * Anonymous callers get a non-personalised version. Sanctum-auth'd
     * devotees get their name (and avatar if profile_photo_path is set)
     * baked into the footer.
     *
     * Rate limited per IP + per devotee to prevent abuse — generation is
     * cheap once cached but the first render is a 1–2s GD pass.
     */
    public function dailyDarshanShareCard(Request $request, DarshanShareCardService $service): JsonResponse
    {
        $photo = DailyDarshanPhoto::query()
            ->where('is_active', true)
            ->orderByDesc('captured_on')
            ->orderByDesc('id')
            ->first();

        if (! $photo) {
            return $this->error('No darshan photo available yet.', 404);
        }

        $format = $request->input('format', DarshanShareCardService::FORMAT_STORY);

        // Auth-optional: the route is registered without middleware so
        // anonymous calls work. We accept both auth surfaces:
        //   • Sanctum bearer token (Flutter app)
        //   • Devotee session cookie (web — when a logged-in donor uses
        //     the share button on /darshan from a browser)
        // Whichever resolves first wins.
        /** @var Devotee|null $devotee */
        $devotee = auth('sanctum')->user() ?? auth('devotee')->user();

        // Rate limit: 20 renders / hour per actor (devotee id or IP). The
        // cached path is free, so this only kicks in on a flood of new
        // permutations.
        $bucketKey = 'darshan-card:' . ($devotee
            ? 'dev:' . $devotee->getKey()
            : 'ip:' . sha1((string) $request->ip()));
        if (RateLimiter::tooManyAttempts($bucketKey, 20)) {
            $retry = RateLimiter::availableIn($bucketKey);
            return $this->error("Too many requests. Try again in {$retry}s.", 429);
        }
        RateLimiter::hit($bucketKey, 3600);

        // Admin-designed template first (uploaded background + overlay
        // layout, per-language); the built-in drawn design remains the
        // fallback when no active template exists for this format or
        // the template render fails.
        $result = null;
        $template = \App\Models\DarshanCardTemplate::forFormat((string) $format);
        if ($template) {
            $result = app(\App\Services\DarshanCardTemplateService::class)
                ->generate($template, $photo, $devotee, app()->getLocale());
        }

        $result ??= $service->generate($photo, $devotee, $format);

        return $this->success([
            'url' => $result['url'],
            'format' => $result['format'],
            'width' => $result['width'],
            'height' => $result['height'],
            'cached' => $result['cached'],
            'share_text' => $this->shareText($photo, $devotee),
        ]);
    }

    /**
     * Build the default WhatsApp/SMS body the Flutter share sheet
     * pre-fills. Keeps the temple URL in every share so non-logged-in
     * recipients land back on the site.
     */
    private function shareText(DailyDarshanPhoto $photo, ?Devotee $devotee): string
    {
        // Both halves follow the devotee's language: the photo's own caption
        // via the model's locale accessor (was pinned to caption_gu), and the
        // fallback greeting via darshan.default_caption. SetApiLocale has
        // already applied X-Locale by the time this runs.
        $caption = $photo->caption ?: __('darshan.default_caption');
        $url = 'https://patadiyahanumanji.com';
        $name = $devotee?->name;

        return $name
            ? "{$caption}\n\n— {$name}\n{$url}"
            : "{$caption}\n\n{$url}";
    }

    /**
     * Active status/greeting templates the devotee can personalise.
     */
    public function statusTemplates(): JsonResponse
    {
        $templates = \App\Support\LocalizedCache::remember('content.status_templates.v2', 900, function () {
            return \App\Models\StatusTemplate::active()
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(function (\App\Models\StatusTemplate $t) {
                    // Devotee-photo slot as fractions of the template size so
                    // the app can overlay the user's DP on the preview tile.
                    $photoSlot = null;
                    if ($t->width && $t->height) {
                        foreach (($t->greeting_card_config['overlays'] ?? []) as $overlay) {
                            if (($overlay['type'] ?? 'text') === 'image') {
                                $photoSlot = [
                                    'x' => round(($overlay['x'] ?? 0) / $t->width, 4),
                                    'y' => round(($overlay['y'] ?? 0) / $t->height, 4),
                                    'w' => round(($overlay['width'] ?? 0) / $t->width, 4),
                                    'h' => round(($overlay['height'] ?? 0) / $t->height, 4),
                                    'shape' => $overlay['shape'] ?? 'square',
                                ];
                                break;
                            }
                        }
                    }

                    return [
                        'id' => $t->id,
                        'title' => $t->title,
                        'title_gu' => $t->title_gu,
                        'title_hi' => $t->title_hi,
                        'title_en' => $t->title_en,
                        'preview_url' => image_url($t->greeting_card_template),
                        // Intrinsic pixel size (null on legacy rows the
                        // backfill couldn't read) → app falls back to 9:16.
                        'width' => $t->width,
                        'height' => $t->height,
                        'photo_slot' => $photoSlot,
                    ];
                })->values();
        });

        return $this->success($templates);
    }

    /**
     * Generate (or return the cached) personalised status card for one
     * template. Auth-optional, mirroring dailyDarshanShareCard: logged-in
     * devotees get their name/photo composited; guests get the plain card.
     */
    public function statusCard(Request $request, \App\Services\StatusCardService $service): JsonResponse
    {
        $template = \App\Models\StatusTemplate::active()->find($request->input('template_id'));
        if (! $template) {
            return $this->error('Template not found.', 404);
        }

        /** @var Devotee|null $devotee */
        $devotee = auth('sanctum')->user() ?? auth('devotee')->user();

        $bucketKey = 'status-card:' . ($devotee
            ? 'dev:' . $devotee->getKey()
            : 'ip:' . sha1((string) $request->ip()));
        if (RateLimiter::tooManyAttempts($bucketKey, 20)) {
            $retry = RateLimiter::availableIn($bucketKey);
            return $this->error("Too many requests. Try again in {$retry}s.", 429);
        }
        RateLimiter::hit($bucketKey, 3600);

        // Optional one-off photo: the devotee can send a different picture
        // instead of their profile photo (multipart field `photo`).
        $photoBytes = null;
        if ($request->hasFile('photo')) {
            $request->validate([
                'photo' => ['file', 'image', 'max:8192'],
            ]);
            $photoBytes = file_get_contents($request->file('photo')->getRealPath()) ?: null;
        }

        $result = $service->generate($template, $devotee, $photoBytes);
        if ($result === null) {
            return $this->error('Could not generate the card. Try again shortly.', 500);
        }

        return $this->success([
            'url' => $result['url'],
            'cached' => $result['cached'],
            'title' => $template->title,
            // Optional admin caption; null when left blank so the app
            // shares the image with no accompanying text.
            'share_text' => $template->share_text !== '' ? $template->share_text : null,
        ]);
    }

    /**
     * Wording hotfixes the app merges over its bundled gu/hi/en strings
     * on launch. Public + heavily cached: an empty overrides map is the
     * normal steady state. `version` lets the client skip rewriting its
     * local cache when nothing changed.
     */
    public function appStrings(): JsonResponse
    {
        $payload = \Illuminate\Support\Facades\Cache::remember(
            \App\Models\AppStringOverride::CACHE_KEY,
            300,
            function () {
                $rows = \App\Models\AppStringOverride::where('is_active', true)->get();

                $overrides = ['gu' => [], 'hi' => [], 'en' => []];
                foreach ($rows as $row) {
                    if (array_key_exists($row->locale, $overrides)) {
                        $overrides[$row->locale][$row->key] = $row->value;
                    }
                }

                return [
                    // Content hash, not max(updated_at): deleting or
                    // deactivating the newest row must still change the
                    // version, or clients would keep their stale cache
                    // (timestamps can only regress in that case).
                    'version' => sha1(json_encode($overrides)),
                    'overrides' => $overrides,
                ];
            },
        );

        return $this->success($payload);
    }

    /**
     * Return live darshan stream configuration from system settings.
     */
    public function liveDarshan(): JsonResponse
    {
        $streamUrl = SystemSetting::getValue('live_darshan_stream_url', '')
            ?: SystemSetting::getValue('youtube_live_url', '');
        $channelId = SystemSetting::getValue('live_darshan_channel_id', '')
            ?: SystemSetting::getValue('youtube_channel_id', '');

        // Schedule gate: live only when a stream URL exists AND the current
        // IST time falls inside a darshan window (regular Sun–Fri / special
        // Saturday). Outside the window clients show the placeholder with
        // the next-darshan time.
        $schedule = \App\Models\DarshanTiming::scheduleNow();
        $isLive = ! empty($streamUrl) && $schedule['is_open'];

        // Current live video id (resolves channel-style /live URLs too) —
        // drives the real live thumbnail + in-app playback. Only probed
        // while live; outside darshan hours clients show the placeholder.
        $liveVideoId = $isLive
            ? \App\Support\YouTubeLive::resolveVideoId($streamUrl, $channelId ?: null)
            : null;

        // Placeholder for the off state: the admin-uploaded image from
        // System Settings → General wins; today's daily darshan photo is
        // the fallback when none is set.
        $customPlaceholder = SystemSetting::getValue('live_darshan_placeholder_image', '');
        $photo = $customPlaceholder
            ? null
            : \App\Models\DailyDarshanPhoto::where('is_active', true)
                ->orderByDesc('captured_on')->orderByDesc('id')->first();
        // Prefer the medium rendition: this URL backs a 16:9 card, and the
        // original is a full-resolution phone photo the client decodes at
        // source size. Same key, same picture, ~4x less decoded memory;
        // falls back to the original for rows not yet backfilled.
        $placeholderUrl = $customPlaceholder
            ? image_url($customPlaceholder)
            : ($photo?->medium_path
                ? image_url($photo->medium_path)
                : ($photo?->image_path ? image_url($photo->image_path) : null));

        return $this->success([
            'stream_url' => $streamUrl,
            'is_live' => $isLive,
            'within_hours' => $schedule['is_open'],
            'next_darshan_at' => $schedule['next_opening']?->format('h:i A'),
            'next_darshan_day' => $schedule['next_opening']?->isToday()
                ? 'today'
                : ($schedule['next_opening']?->isTomorrow() ? 'tomorrow' : $schedule['next_opening']?->format('D')),
            'placeholder_image_url' => $placeholderUrl,
            'channel_id' => $channelId,
            'live_video_id' => $liveVideoId,
            'live_thumbnail_url' => $liveVideoId
                ? \App\Support\YouTubeLive::thumbnailUrl($liveVideoId)
                : null,
        ]);
    }

    /**
     * Return all active donation campaigns.
     */
    public function campaigns(): JsonResponse
    {
        // Admin-chosen campaign for the app home carousel (Home Page Design
        // → Mobile App Home). It leads the list; the app renders the list
        // in server order, so first = the card devotees see on Home.
        $homeCampaignId = (int) SystemSetting::getValue('site_app_home_campaign_id', '0');

        $campaigns = DonationCampaign::query()
            ->with('subCauses')
            ->where('is_active', true)
            ->when($homeCampaignId > 0, fn ($q) => $q->orderByRaw('(id = ?) desc', [$homeCampaignId]))
            ->orderByDesc('is_featured')
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

        $campaign->load('subCauses');

        return $this->success($this->mapCampaign($campaign));
    }

    /**
     * Return current darshan timings.
     * Cached for 30 minutes.
     */
    public function darshanTimings(): JsonResponse
    {
        $timings = \App\Support\LocalizedCache::remember('darshan_timings.active', 1800, function () {
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
                    'label_gu' => $timing->label_gu,
                    'label_hi' => $timing->label_hi,
                    'label_en' => $timing->label_en,
                    'morning_open' => $timing->morning_open,
                    'morning_close' => $timing->morning_close,
                    'afternoon_open' => $timing->afternoon_open,
                    'afternoon_close' => $timing->afternoon_close,
                    'evening_open' => $timing->evening_open,
                    'evening_close' => $timing->evening_close,
                    'aarti_morning' => $timing->aarti_morning,
                    'aarti_evening' => $timing->aarti_evening,
                    'special_note' => $timing->{'special_note_' . app()->getLocale()} ?? $timing->special_note_gu,
                    'special_note_gu' => $timing->special_note_gu,
                    'special_note_hi' => $timing->special_note_hi,
                    'special_note_en' => $timing->special_note_en,
                ]);
        });

        return $this->success($timings);
    }

    /**
     * Return gallery images, optionally filtered by category.
     * Cached for 15 minutes per category.
     *
     * PAGINATION — opt-in, deliberately.
     *
     * The shipped 1.4.8 client (`galleryProvider`, gallery_screen.dart)
     * sends only `category`, reads `data['data'] as List`, and has no
     * "load more" affordance anywhere. Defaulting to a page size would
     * therefore silently hide two thirds of the gallery from every phone
     * already in the field. So:
     *
     *   • no `page` / `per_page` in the query  → identical response to
     *     before: a flat list, capped at 200, plus the same envelope keys.
     *   • either param present → that page's slice, STILL a flat list in
     *     `data`, with an additive top-level `meta` block. Older clients
     *     never send the params and never see `meta`; a client that does
     *     send them gets 30 (max 100) rows per request.
     */
    public function gallery(Request $request): JsonResponse
    {
        $category = $request->query('category');

        $paginate = $request->has('page') || $request->has('per_page');
        $perPage = max(1, min((int) $request->query('per_page') ?: 30, 100));
        $page = max(1, (int) $request->query('page') ?: 1);

        $cacheKey = $category ? "gallery.{$category}" : 'gallery.all';

        $images = \App\Support\LocalizedCache::remember($cacheKey, 900, function () use ($category) {
            $query = GalleryImage::query()->with('categories')->orderBy('sort_order');

            if ($category) {
                // Match the pivot, not the primary column, so a photo filed
                // under several categories shows up under each of them. This
                // widens results for the app already installed, with no client
                // change needed.
                $query->whereHas('categories', fn ($q) => $q->where('category_slug', $category));
            }

            // Bound the unbounded fetch. Keeps the flat-list response envelope
            // ($this->success([...])) unchanged for app compatibility.
            return $query->limit(200)->get()->map(fn (GalleryImage $image) => [
                'id' => $image->id,
                'type' => $image->type ?? 'photo',
                'title' => $image->title,
                'description' => $image->description,
                'image_url' => $image->image_path ? image_url($image->image_path) : null,
                'thumbnail_url' => $image->thumbnail_path ? image_url($image->thumbnail_path) : null,
                'medium_url' => $image->medium_path ? image_url($image->medium_path) : null,
                'video_url' => $image->video_url,
                // MUST stay a scalar string. The shipped app parses this with
                // `json['category'] as String?`; an array throws a TypeError
                // that its `on DioException` catch does not cover, leaving the
                // gallery permanently empty. `categories` is additive — older
                // clients simply never read it.
                'category' => $image->category,
                'categories' => $image->categories->pluck('slug')->values(),
                'is_wallpaper' => $image->is_wallpaper,
            ]);
        });

        if (! $paginate) {
            return $this->success($images);
        }

        $total = $images->count();
        $slice = $images->values()->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->success($slice, meta: [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            'has_more' => $page * $perPage < $total,
        ]);
    }

    /**
     * Admin-managed gallery categories for the app's filter chips.
     * Localized via X-Locale; cached until the list changes.
     */
    public function galleryCategories(): JsonResponse
    {
        $categories = \App\Support\LocalizedCache::remember('gallery.categories', 900, function () {
            return \App\Models\GalleryCategory::orderBy('sort_order')
                ->get()
                ->map(fn (\App\Models\GalleryCategory $c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                ]);
        });

        return $this->success($categories);
    }

    /**
     * Return published events, optionally filtered by type.
     */
    public function events(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $includePast = $request->boolean('include_past');

        // Hide events that ended more than 1 day ago; order upcoming first.
        $cutoff = now()->subDay()->toDateString();

        $query = Event::query()
            ->with('media')
            ->where('status', 'published');

        if ($includePast) {
            // Include recently-ended events (last 6 months) so the app can
            // show a "Recent events" section alongside upcoming ones.
            $pastFloor = now()->subMonths(6)->toDateString();
            $query->where('start_date', '>=', $pastFloor);
        } else {
            $query->where(function ($q) use ($cutoff) {
                // Event has no end_date AND starts today or later
                $q->where(function ($qq) use ($cutoff) {
                    $qq->whereNull('end_date')
                        ->where('start_date', '>=', $cutoff);
                })
                // OR event has an end_date still today or later
                ->orWhere('end_date', '>=', $cutoff);
            });
        }

        $query->orderByDesc('is_featured')
            ->orderBy('start_date');

        if ($type) {
            $query->where('event_type', $type);
        }

        $cutoffDate = now()->subDay();

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
            'image_url' => $event->image_path ? image_url($event->image_path) : null,
            'media' => $event->media->map(fn (\App\Models\EventMedia $m) => [
                'type' => $m->media_type,
                'url' => $m->media_type === 'video'
                    ? $m->video_url
                    : ($m->image_path ? image_url($m->image_path) : null),
            ])->filter(fn ($m) => $m['url'] !== null)->values(),
            'is_featured' => $event->is_featured,
            'event_type' => $event->event_type?->value,
            'is_past' => $event->end_date
                ? $event->end_date->lt($cutoffDate)
                : ($event->start_date?->lt($cutoffDate) ?? false),
        ]);

        return $this->success($events);
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

        $submission = ContactSubmission::create(array_merge($validated, [
            'ip_address' => $request->ip(),
            'is_read' => false,
        ]));

        app(\App\Services\Notifications\NotificationService::class)->dispatch(
            'contact.submitted',
            [
                'submission' => $submission,
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            idempotencyKey: "contact-submission:{$submission->id}",
        );

        return $this->success(null, 'તમારો સંદેશ સફળતાપૂર્વક મોકલાયો.');
    }

    /**
     * Published top-level CMS pages for the app's More menu. The app renders
     * each via the /pages/{slug}/embed WebView, so admins adding a page in
     * Filament makes it appear in the app without a release.
     */
    public function pages(): JsonResponse
    {
        $pages = \App\Support\LocalizedCache::remember('content.pages.v1', 1800, function () {
            return \App\Models\Page::where('status', 'published')
                ->whereNull('parent_slug')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (\App\Models\Page $p) => [
                    'slug' => $p->slug,
                    'title' => $p->title,
                ])
                ->values();
        });

        return $this->success(['pages' => $pages]);
    }

    /**
     * Return temple info (name, contact, address, about, rules, nearby).
     * Draws from SystemSetting so admin can edit without a code change.
     */
    public function templeInfo(): JsonResponse
    {
        // Locale-keyed cache: the payload bakes in locale-resolved settings
        // (X-Locale header via SetApiLocale), so a plain Cache::remember
        // would leak the first requester's language to everyone.
        $info = \App\Support\LocalizedCache::remember('content.temple_info.v1', 1800, function () {
            return [
                'name' => SystemSetting::getLocalized('trust_name', null, 'શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ'),
                'phone' => SystemSetting::getValue('trust_phone'),
                'email' => SystemSetting::getValue('trust_email'),
                'address' => SystemSetting::getLocalized('trust_address'),
                'about' => SystemSetting::getLocalized('trust_about'),
                'rules' => SystemSetting::getLocalized('trust_rules'),
                'nearby' => SystemSetting::getLocalized('trust_nearby'),
                'facilities' => SystemSetting::getLocalized('trust_facilities'),
                'map_url' => SystemSetting::getValue('trust_map_url'),
                'youtube_channel_url' => SystemSetting::getValue('youtube_channel_url'),
                'whatsapp_number' => SystemSetting::getValue('trust_whatsapp'),
            ];
        });

        return $this->success($info);
    }

    /**
     * Return active trustees (ordered), with resolved + all-language fields.
     */
    public function trustees(): JsonResponse
    {
        $trustees = \App\Support\LocalizedCache::remember('content.trustees.v1', 1800, function () {
            return Trustee::active()->ordered()->get()->map(fn (Trustee $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'name_gu' => $t->name_gu,
                'name_hi' => $t->name_hi,
                'name_en' => $t->name_en,
                'role' => $t->role,
                'role_gu' => $t->role_gu,
                'role_hi' => $t->role_hi,
                'role_en' => $t->role_en,
                'location' => $t->location,
                'photo_url' => $t->photo_path ? image_url($t->photo_path) : null,
            ])->values();
        });

        return $this->success($trustees);
    }

    /**
     * Return active donation types.
     */
    public function donationTypes(): JsonResponse
    {
        $types = \App\Support\LocalizedCache::remember('donation_types.active', 1800, function () {
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
            // Resolved (server locale) + all variants so the app can switch
            // language client-side without refetching.
            'title' => $campaign->title,
            'title_gu' => $campaign->title_gu,
            'title_hi' => $campaign->title_hi,
            'title_en' => $campaign->title_en,
            'description' => $campaign->description,
            'description_gu' => $campaign->description_gu,
            'description_hi' => $campaign->description_hi,
            'description_en' => $campaign->description_en,
            'writeup' => $campaign->writeup,
            'writeup_gu' => $campaign->writeup_gu,
            'writeup_hi' => $campaign->writeup_hi,
            'writeup_en' => $campaign->writeup_en,
            'goal_amount' => (float) $campaign->goal_amount,
            'raised_amount' => (float) $campaign->raised_amount,
            'donor_count' => $campaign->donor_count,
            'image_url' => $campaign->cover_image_url,
            'featured_video_url' => $campaign->featured_video_url,
            // Gallery: resolve each stored key/URL through image_url() (idempotent
            // for absolute URLs like uploaded videos / links).
            'media' => collect($campaign->media ?? [])
                ->map(fn ($m) => [
                    'url' => isset($m['url']) ? image_url($m['url']) : null,
                    'type' => $m['type'] ?? 'image',
                ])
                ->filter(fn ($m) => $m['url'] !== null)
                ->values(),
            'start_date' => $campaign->start_date?->toDateString(),
            'sub_causes' => $campaign->subCauses
                ->where('is_active', true)
                ->map(fn ($sc) => [
                    'id' => $sc->id,
                    'title' => $sc->title,
                    'title_gu' => $sc->title_gu,
                    'title_hi' => $sc->title_hi,
                    'title_en' => $sc->title_en,
                    'goal_amount' => $sc->goal_amount !== null ? (float) $sc->goal_amount : null,
                ])->values(),
            'is_featured' => (bool) $campaign->is_featured,
            'progress_percent' => $campaign->goal_amount > 0
                ? round(((float) $campaign->raised_amount / (float) $campaign->goal_amount) * 100, 2)
                : 0,
        ];
    }
}
