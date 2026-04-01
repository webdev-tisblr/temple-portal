<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\DarshanTiming;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\Page;
use App\Models\Seva;
use Artesaos\SEOTools\Facades\SEOMeta;
use Artesaos\SEOTools\Facades\OpenGraph;
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

        $announcements = Announcement::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('published_at')->take(3)->get();

        $campaigns = DonationCampaign::where('is_active', true)->get();

        $intro = Cache::remember('page_parichay', 3600, fn () =>
            Page::where('slug', 'parichay')->where('status', 'published')->first()
        );

        SEOMeta::setTitle('શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ | અંતરજાલ, ગાંધીધામ');
        SEOMeta::setDescription('ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ. ઓનલાઇન સેવા બુકિંગ, દાન, લાઇવ દર્શન.');
        OpenGraph::setUrl(url('/'));
        OpenGraph::addProperty('type', 'website');

        return view('pages.home', compact('sevas', 'events', 'timings', 'announcements', 'campaigns', 'intro'));
    }
}
