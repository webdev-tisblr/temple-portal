<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ContactCategory;
use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(): View
    {
        $trustPhone = SystemSetting::getValue('trust_phone');
        $trustEmail = SystemSetting::getValue('trust_email');
        $trustAddress = SystemSetting::getLocalized('trust_address');

        SEOMeta::setTitle('સંપર્ક — શ્રી પાતાળિયા હનુમાનજી');

        return view('pages.contact', compact('trustPhone', 'trustEmail', 'trustAddress'));
    }

    public function submit(Request $request): RedirectResponse
    {
        // Login required since 2026-08-17 (route middleware enforces it) —
        // this guard is the belt to that braces, so the identity read below
        // can never be null.
        $devotee = Auth::guard('devotee')->user();
        if ($devotee === null) {
            return redirect()->guest(route('login'));
        }

        // Rate limit per devotee, not per IP: a whole family behind one
        // household connection used to share the 3/hour budget.
        $key = 'contact-submit:' . $devotee->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['message' => 'ઘણા બધા પ્રયાસો. કૃપા કરીને થોડીવાર પછી ફરી પ્રયાસ કરો.']);
        }
        RateLimiter::hit($key, 3600);

        // name/phone/email are NOT accepted from the request — they come from
        // the signed-in profile, so the form cannot be used to send a message
        // under someone else's name.
        $validated = $request->validate([
            'category' => ['nullable', Rule::enum(ContactCategory::class)],
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
        ]);

        $submission = ContactSubmission::fromDevotee($devotee, $validated, $request->ip());

        // Notify admin via every enabled channel (email + WhatsApp once
        // the admin enables those templates from /admin/system).
        app(NotificationService::class)->dispatch(
            'contact.submitted',
            [
                'submission' => $submission,
                // Resolved label, not the enum — formatForDisplay would print
                // the raw backing value ("seva_request") to the admin.
                'category_label' => $submission->category->label(),
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            idempotencyKey: "contact-submission:{$submission->id}",
        );

        return back()->with('success', 'તમારો સંદેશ મોકલવામાં આવ્યો છે. અમે ટૂંક સમયમાં સંપર્ક કરીશું.');
    }
}
