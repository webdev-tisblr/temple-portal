<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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

        $events = Cache::remember('homepage_events', 900, fn () =>
            Event::where('status', 'published')->where('start_date', '>=', now())->orderBy('start_date')->take(3)->get()
        );

        $timings = Cache::remember('darshan_timings', 3600, fn () =>
            DarshanTiming::where('is_active', true)->where('day_type', 'regular')->first()
        );

        // Featured campaign — pick the one with the highest raised amount
        // (or any active one as a fallback). The home page shows ONE; the
        // /projects page shows the full grid.
        $featuredCampaign = Cache::remember('homepage_featured_campaign', 600, fn () =>
            DonationCampaign::where('is_active', true)
                ->orderByDesc('raised_amount')
                ->first()
        );

        // Gallery preview — last 8 images for the home strip.
        $galleryPreview = Cache::remember('homepage_gallery_preview', 1800, fn () =>
            GalleryImage::orderByDesc('id')->take(8)->get()
        );

        $intro = Cache::remember('page_parichay', 3600, fn () =>
            Page::where('slug', 'parichay')->where('status', 'published')->first()
        );

        SEOMeta::setTitle('શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ | અંતરજાલ, ગાંધીધામ');
        SEOMeta::setDescription('ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ઓનલાઇન સેવા બુકિંગ, દાન, લાઇવ દર્શન.');
        OpenGraph::setUrl(url('/'));
        OpenGraph::addProperty('type', 'website');

        return view('pages.home', compact(
            'sevas',
            'events',
            'timings',
            'featuredCampaign',
            'galleryPreview',
            'intro',
        ));
    }
}
