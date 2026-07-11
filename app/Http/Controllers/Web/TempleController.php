<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DailyDarshanPhoto;
use App\Models\DarshanTiming;
use App\Models\SystemSetting;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class TempleController extends Controller
{
    public function darshan(): View
    {
        $timings = Cache::remember('darshan_timings_all', 3600, fn () =>
            DarshanTiming::where('is_active', true)->get()
        );
        $youtubeUrl = SystemSetting::getValue('youtube_live_url');
        // Locale-aware temple rules; fall back to the legacy single-language
        // key when a translation hasn't been entered.
        $locale = app()->getLocale();
        $templeRules = SystemSetting::getValue("temple_rules_{$locale}")
            ?: SystemSetting::getValue('temple_rules');

        // Today's darshan photo prominent at the top of the page.
        // Same logic as HomeController — today's first, latest fallback.
        $dailyDarshanPhoto = Cache::remember('darshan_page_daily_photo', 600, function () {
            return DailyDarshanPhoto::where('is_active', true)
                ->whereDate('captured_on', today())
                ->latest('id')
                ->first()
                ?? DailyDarshanPhoto::where('is_active', true)
                    ->orderByDesc('captured_on')
                    ->orderByDesc('id')
                    ->first();
        });

        SEOMeta::setTitle('દર્શન સમય — શ્રી પાતાળિયા હનુમાનજી');
        SEOMeta::setDescription('મંદિરના દૈનિક દર્શન સમય અને લાઇવ દર્શન.');

        return view('pages.darshan', compact('timings', 'youtubeUrl', 'templeRules', 'dailyDarshanPhoto'));
    }

    public function trustees(): View
    {
        SEOMeta::setTitle('ટ્રસ્ટીઓ — શ્રી પાતાળિયા હનુમાનજી');
        $trustees = \App\Models\Trustee::active()->ordered()->get();
        return view('pages.trustees', compact('trustees'));
    }

    public function pujari(): View
    {
        SEOMeta::setTitle('પૂજારી — શ્રી પાતાળિયા હનુમાનજી');
        return view('pages.pujari');
    }

    public function rules(): View
    {
        SEOMeta::setTitle('નિયમો — શ્રી પાતાળિયા હનુમાનજી');
        return view('pages.rules');
    }
}
