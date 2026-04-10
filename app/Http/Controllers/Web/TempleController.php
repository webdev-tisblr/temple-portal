<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
        $templeRules = SystemSetting::getValue('temple_rules');

        SEOMeta::setTitle('દર્શન સમય — શ્રી પાતળિયા હનુમાનજી');
        SEOMeta::setDescription('મંદિરના દૈનિક દર્શન સમય અને લાઇવ દર્શન.');

        return view('pages.darshan', compact('timings', 'youtubeUrl', 'templeRules'));
    }

    public function trustees(): View
    {
        SEOMeta::setTitle('ટ્રસ્ટીઓ — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.trustees');
    }

    public function pujari(): View
    {
        SEOMeta::setTitle('પૂજારી — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.pujari');
    }

    public function rules(): View
    {
        SEOMeta::setTitle('નિયમો — શ્રી પાતળિયા હનુમાનજી');
        return view('pages.rules');
    }
}
