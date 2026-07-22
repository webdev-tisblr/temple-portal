<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DarshanTiming;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\Seva;
use App\Models\SystemSetting;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $sevas = Cache::remember('homepage_sevas', 600, fn () =>
            Seva::where('is_active', true)->orderBy('sort_order')->take(6)->get()
        );

        // Home shows 3 visible at a time in a scroll-snap carousel; we
        // fetch up to 9 so admins running a full festival season fill
        // the carousel naturally without needing to touch this list.
        $events = Cache::remember('homepage_events', 900, fn () =>
            Event::where('status', 'published')->where('start_date', '>=', now())->orderBy('start_date')->take(9)->get()
        );

        $timings = Cache::remember('darshan_timings', 3600, fn () =>
            DarshanTiming::where('is_active', true)->where('day_type', 'regular')->first()
        );

        // Active campaigns — render adaptively on home:
        //   1 campaign  → single full-width card
        //   2 campaigns → 2-up grid full width
        //   3+ campaigns → 3-card carousel
        // The view decides layout based on count. We cap at 6 here so
        // a temple running many campaigns doesn't dump everything on
        // the home page — the full grid still lives at /projects.
        $campaigns = Cache::remember('homepage_campaigns', 600, fn () =>
            DonationCampaign::where('is_active', true)
                ->orderByDesc('is_featured')
                ->orderByDesc('raised_amount')
                ->take(6)
                ->get()
        );

        // Gallery preview — featured images only.
        // C5: home should NOT show every recent gallery image — only
        // the curated featured set (existing is_wallpaper flag is the
        // closest semantic we have today). Falls back to latest if
        // none flagged so empty-state never happens.
        $galleryPreview = Cache::remember('homepage_gallery_preview', 1800, function () {
            $featured = GalleryImage::where('is_wallpaper', true)
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->take(8)
                ->get();
            if ($featured->isNotEmpty()) return $featured;
            return GalleryImage::orderByDesc('id')->take(8)->get();
        });

        // Hero background + text overrides (Admin → Home Page Settings).
        // A single image OR looping video; falls back to the bundled photo.
        $hero = Cache::remember('home.hero_config.v1', 300, function () {
            $g = fn (string $k, string $d = '') => (string) SystemSetting::getValue("site_{$k}", $d);

            return [
                'media_type' => $g('hero_media_type', 'image') ?: 'image',
                'image' => $g('hero_image'),
                'video_type' => $g('hero_video_type', 'upload') ?: 'upload',
                'video_file' => $g('hero_video_file'),
                'video_url' => $g('hero_video_url'),
                'video_audio' => $g('hero_video_audio') === '1',
                'video_controls' => $g('hero_video_controls') === '1',
                'overlay' => (int) ($g('hero_overlay', '40') ?: '40'),
                'heading' => ['gu' => $g('hero_heading_gu'), 'hi' => $g('hero_heading_hi'), 'en' => $g('hero_heading_en')],
                'sub' => ['gu' => $g('hero_sub_gu'), 'hi' => $g('hero_sub_hi'), 'en' => $g('hero_sub_en')],
            ];
        });

        // Admin-selected featured cards for the hero-side highlight slider.
        // Empty pickers fall back to the latest campaign / event (previous
        // auto behaviour). The hall card shows unless explicitly turned off.
        $cardCfg = Cache::remember('home.hero_cards.v1', 300, function () {
            $ids = fn (string $k) => collect(json_decode(SystemSetting::getValue("site_{$k}", '[]'), true) ?: [])
                ->map(fn ($v) => (int) $v)->filter()->values();
            $hallRaw = (string) SystemSetting::getValue('site_card_show_hall', '');

            return [
                'campaign_ids' => $ids('card_campaign_ids'),
                'event_ids' => $ids('card_event_ids'),
                'show_hall' => $hallRaw === '' || $hallRaw === '1',
            ];
        });

        $heroCampaigns = $cardCfg['campaign_ids']->isNotEmpty()
            ? DonationCampaign::whereIn('id', $cardCfg['campaign_ids'])->get()
                ->sortBy(fn ($c) => $cardCfg['campaign_ids']->search($c->id))->values()
            : $campaigns->take(1);

        $heroEvents = $cardCfg['event_ids']->isNotEmpty()
            ? Event::whereIn('id', $cardCfg['event_ids'])->get()
                ->sortBy(fn ($e) => $cardCfg['event_ids']->search($e->id))->values()
            : $events->take(1);

        $heroShowHall = $cardCfg['show_hall'];

        // ── Redesign data (2026-07-12 design import) ────────────────────
        // Live open/closed state + today's timing row for the badge,
        // ticker and sticky darshan widget. All IST-aware via scheduleNow.
        $schedule = DarshanTiming::scheduleNow();
        $todayTiming = Cache::remember('home.today_timing.v1', 1800, function () {
            $type = now()->setTimezone('Asia/Kolkata')->isSaturday() ? 'special' : 'regular';

            return DarshanTiming::where('is_active', true)->where('day_type', $type)->first()
                ?? DarshanTiming::where('is_active', true)->where('day_type', 'regular')->first();
        });
        // When open, find the current window's closing time for the badge.
        $closesAt = null;
        if ($schedule['is_open'] && $todayTiming) {
            $nowIst = now()->setTimezone('Asia/Kolkata');
            foreach ([['morning_open', 'morning_close'], ['afternoon_open', 'afternoon_close'], ['evening_open', 'evening_close']] as [$o, $c]) {
                $open = $todayTiming->getAttributes()[$o] ?? null;
                $close = $todayTiming->getAttributes()[$c] ?? null;
                if ($open && $close) {
                    $start = $nowIst->copy()->setTimeFromTimeString($open);
                    $end = $nowIst->copy()->setTimeFromTimeString($close);
                    if ($nowIst->between($start, $end)) {
                        $closesAt = $end->format('h:i A');
                        break;
                    }
                }
            }
        }

        // Which hall shows in the home card / band is admin-configurable
        // (Home Page Settings → Featured Cards → "Which hall to feature").
        // Falls back to the most recent active hall when unset or the chosen
        // one is inactive/deleted.
        $hall = Cache::remember('home.hall.v1', 900, function () {
            $configuredId = (int) SystemSetting::getValue('site_card_hall_id', '');

            if ($configuredId > 0) {
                $chosen = \App\Models\Hall::where('is_active', true)->find($configuredId);
                if ($chosen) {
                    return $chosen;
                }
            }

            return \App\Models\Hall::where('is_active', true)->first();
        });

        $visit = [
            'address' => SystemSetting::getValue('trust_address', 'અંતરજાળ, ગાંધીધામ, કચ્છ — 370110'),
            'phone' => SystemSetting::getValue('trust_phone', ''),
            'email' => SystemSetting::getValue('trust_email', ''),
            'map_url' => SystemSetting::getValue('trust_map_url', ''),
        ];

        SEOMeta::setTitle('શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ | અંતરજાળ, ગાંધીધામ');
        SEOMeta::setDescription('ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ઓનલાઇન સેવા બુકિંગ, દાન, લાઇવ દર્શન.');
        OpenGraph::setUrl(url('/'));
        OpenGraph::addProperty('type', 'website');

        return view('pages.home', compact(
            'sevas',
            'events',
            'timings',
            'campaigns',
            'galleryPreview',
            'hero',
            'heroCampaigns',
            'heroEvents',
            'heroShowHall',
            'schedule',
            'todayTiming',
            'closesAt',
            'hall',
            'visit',
        ));
    }
}
