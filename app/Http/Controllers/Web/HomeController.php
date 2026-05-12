<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DailyDarshanPhoto;
use App\Models\DarshanTiming;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\Page;
use App\Models\Seva;
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

        $intro = Cache::remember('page_parichay', 3600, fn () =>
            Page::where('slug', 'parichay')->where('status', 'published')->first()
        );

        // Today's darshan photo — short cache so admin uploads land
        // within ~10 min on a busy day. Falls back to the most-recent
        // active photo so the widget never goes empty.
        $dailyDarshanPhoto = Cache::remember('homepage_daily_darshan_photo', 600, function () {
            return DailyDarshanPhoto::where('is_active', true)
                ->whereDate('captured_on', today())
                ->latest('id')
                ->first()
                ?? DailyDarshanPhoto::where('is_active', true)
                    ->orderByDesc('captured_on')
                    ->orderByDesc('id')
                    ->first();
        });

        SEOMeta::setTitle('શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ | અંતરજાલ, ગાંધીધામ');
        SEOMeta::setDescription('ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ઓનલાઇન સેવા બુકિંગ, દાન, લાઇવ દર્શન.');
        OpenGraph::setUrl(url('/'));
        OpenGraph::addProperty('type', 'website');

        return view('pages.home', compact(
            'sevas',
            'events',
            'timings',
            'campaigns',
            'galleryPreview',
            'intro',
            'dailyDarshanPhoto',
        ));
    }
}
