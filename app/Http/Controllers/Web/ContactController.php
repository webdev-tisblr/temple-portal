<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(): View
    {
        $trustPhone = SystemSetting::getValue('trust_phone');
        $trustEmail = SystemSetting::getValue('trust_email');
        $trustAddress = SystemSetting::getValue('trust_address');

        SEOMeta::setTitle('સંપર્ક — શ્રી પાતળિયા હનુમાનજી');

        return view('pages.contact', compact('trustPhone', 'trustEmail', 'trustAddress'));
    }

    public function submit(Request $request): RedirectResponse
    {
        $key = 'contact-submit:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['message' => 'ઘણા બધા પ્રયાસો. કૃપા કરીને થોડીવાર પછી ફરી પ્રયાસ કરો.']);
        }
        RateLimiter::hit($key, 3600);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:15',
            'email' => 'nullable|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:2000',
        ]);

        Log::info('Contact form submission', $validated);

        return back()->with('success', 'તમારો સંદેશ મોકલવામાં આવ્યો છે. અમે ટૂંક સમયમાં સંપર્ક કરીશું.');
    }
}
