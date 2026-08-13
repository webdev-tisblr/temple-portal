<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDonationRequest;
use App\Jobs\Generate80GReceipt;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Models\Payment;
use App\Services\RazorpayService;
use App\Support\SafeRedirect;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DonationWebController extends Controller
{
    public function index(Request $request): View
    {
        // Same ordering as /projects so the two listings agree.
        $campaigns = DonationCampaign::where('is_active', true)
            ->where('start_date', '<=', now())
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->get();

        // ?campaign={id} pre-targets the form at one campaign (hidden
        // campaign_id + sub-cause picker instead of the type dropdown).
        // Used by the iOS app, which must hand donations off to this page
        // (App Store 3.2.2(iv)) — including its campaign donate buttons.
        $selectedCampaign = null;
        if ($request->filled('campaign')) {
            $selectedCampaign = $campaigns->firstWhere('id', (int) $request->query('campaign'));
            $selectedCampaign?->load(['subCauses' => fn ($q) => $q->where('is_active', true)]);
        }

        $donationTypes = DonationType::where('is_active', true)->orderBy('sort_order')->get();

        // Item 5.4 — form state carried back from the PAN interstitial.
        // When a donor ticks "I want an 80G receipt" without a PAN on file
        // we bounce them to the profile page; SafeRedirect brings them back
        // HERE, and these query params restore what they had typed so the
        // donation can be completed rather than re-entered.
        $devotee = Auth::guard('devotee')->user();
        $prefill = [
            'amount' => $request->filled('amount') ? (int) $request->query('amount') : null,
            'donation_type_id' => $request->filled('type') ? (string) $request->query('type') : '',
            'purpose' => (string) $request->query('purpose', ''),
            'anonymous' => $request->boolean('anonymous'),
            // Default the 80G request ON — it is what most donors want, and
            // the PAN gate (not this default) decides what is actually issued.
            'wants_80g' => $request->has('wants_80g') ? $request->boolean('wants_80g') : true,
            'sub_cause_id' => $request->filled('sub_cause') ? (string) $request->query('sub_cause') : '',
        ];

        $hasPan = app(\App\Services\ReceiptService::class)->devoteeHasValid80GPan($devotee);

        // Pre-build JS-ready data (avoid arrow functions in Blade @json)
        $donationTypesJs = $donationTypes->map(function ($t) {
            return [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'extra_fields' => $t->localizedExtraFields(),
            ];
        })->values()->toArray();

        SEOMeta::setTitle('દાન કરો — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતાળિયા હનુમાનજી મંદિર માટે ઓનલાઈન દાન કરો.');

        // In campaign mode the type dropdown is hidden, so the CAMPAIGN's own
        // extra fields are the ones to ask (2026-08-13).
        $campaignExtraFields = $selectedCampaign?->localizedExtraFields() ?? [];

        return view('pages.donation.index', compact(
            'campaigns',
            'campaignExtraFields',
            'donationTypes',
            'donationTypesJs',
            'selectedCampaign',
            'prefill',
            'hasPan',
        ));
    }

    public function create(CreateDonationRequest $request): View|\Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $amount = (float) $validated['amount'];

        // ── Item 5.4: the 80G prompt ────────────────────────────────
        // MANDATORY on the web, not merely nice: on iOS the donate flow IS
        // this website (DonateGate + app_ios_native_donations = 0), so a
        // prompt built only in Flutter would let half the userbase past the
        // rule. The Alpine panel on the form is the friendly version; this
        // is the one that actually holds, and it also covers no-JS.
        //
        // Reuses the return-destination mechanism built for item 3.1
        // (SafeRedirect + session('url.intended')) rather than inventing a
        // second redirect scheme: remember the fully-restored donate URL,
        // send the donor to their profile, and the profile save bounces
        // them straight back here with the form repopulated.
        $wants80g = (bool) ($validated['wants_80g'] ?? true);
        $hasValidPan = app(\App\Services\ReceiptService::class)->devoteeHasValid80GPan($devotee);

        if ($wants80g && ! $hasValidPan) {
            SafeRedirect::remember($this->donateReturnUrl($validated));

            return redirect()
                ->route('dashboard.profile')
                ->with('pan_required_for_80g', true)
                ->with('warning', __('donation.pan_required_body'));
        }

        // Two INDEPENDENT flags (corrected 2026-08-10 after live testing):
        //
        //   is_80g_eligible — does a receipt get issued. Driven by the PAN.
        //   anonymous       — is the name hidden on public donor lists.
        //                     Driven ONLY by the donor ticking Gupt Daan.
        //
        // A donation with no PAN is simply a donation without an 80G
        // receipt: the donor is named and appears normally on the public
        // list. Conversely a Gupt Daan donor WITH a PAN still gets their
        // 80G receipt — anonymity is about public display, not tax
        // documents. Do NOT reintroduce `|| ! $hasValidPan` below.
        $is80gEligible = $wants80g && $hasValidPan;
        $anonymous = (bool) ($validated['anonymous'] ?? false);

        $fy = now()->month >= 4
            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

        // Process extra_data — handle image uploads
        // Field definitions come from the donation TYPE, or — in campaign mode,
        // where the type picker is hidden and donation_type_id is null — from
        // the CAMPAIGN itself (2026-08-13).
        $extraData = $validated['extra_data'] ?? null;
        $definitions = null;

        if (! empty($validated['donation_type_id'])) {
            $definitions = DonationType::find($validated['donation_type_id'])?->extra_fields;
        } elseif (! empty($validated['campaign_id'])) {
            $definitions = \App\Models\DonationCampaign::find($validated['campaign_id'])?->extra_fields;
        }

        $extraData = \App\Support\ExtraFieldValues::store($request, $definitions, $extraData, 'donation-extras');

        try {
            $result = DB::transaction(function () use ($validated, $devotee, $amount, $fy, $extraData, $wants80g, $is80gEligible, $anonymous) {
                $paymentId = (string) Str::uuid();
                $receipt = 'DON-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($amount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'donation_type' => $validated['donation_type'],
                ]);

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => "Donation - {$validated['donation_type']}",
                ]);

                $donation = Donation::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'donation_type' => $validated['donation_type'],
                    'donation_type_id' => $validated['donation_type_id'] ?? null,
                    'purpose' => $validated['purpose'] ?? null,
                    'campaign_id' => $validated['campaign_id'] ?? null,
                    'sub_cause_id' => $validated['sub_cause_id'] ?? null,
                    'extra_data' => $extraData,
                    'wants_80g' => $wants80g,
                    'is_80g_eligible' => $is80gEligible,
                    'anonymous' => $anonymous,
                    'financial_year' => $fy,
                ]);

                return [
                    'donation' => $donation,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'razorpayKeyId' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($amount * 100),
                'currency' => 'INR',
                'description' => 'દાન — ' . ucfirst($validated['donation_type']),
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
                'successUrl' => route('donate.thanks'),
                'failureUrl' => route('home'),
            ]);

        } catch (\Exception $e) {
            Log::error('Web donation failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['donation' => 'દાન બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    /**
     * The /donate URL that restores this half-finished donation, used as
     * the return destination of the PAN interstitial (item 5.4).
     *
     * Only scalar form state travels in the query string — never an
     * uploaded extra_data file, which cannot survive a redirect and is
     * re-picked by the donor. Absolute (route() output) on purpose:
     * SafeRedirect::normalize accepts an own-host URL and reduces it to a
     * path, which is the same shape Redirector::guest() stores.
     *
     * @param  array<string, mixed>  $validated
     */
    private function donateReturnUrl(array $validated): string
    {
        return route('donate', array_filter([
            'campaign' => $validated['campaign_id'] ?? null,
            'sub_cause' => $validated['sub_cause_id'] ?? null,
            'amount' => (int) $validated['amount'],
            'type' => $validated['donation_type_id'] ?? null,
            'purpose' => $validated['purpose'] ?? null,
            'anonymous' => ! empty($validated['anonymous']) ? 1 : null,
            'wants_80g' => 1,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function thankYou(Request $request): View
    {
        $paymentId = $request->query('payment_id');
        $orderId = $request->query('order_id');
        $signature = $request->query('signature');

        $verified = false;
        $donation = null;

        if ($paymentId && $orderId && $signature) {
            $razorpayService = app(RazorpayService::class);
            $verified = $razorpayService->verifyPaymentSignature($orderId, $paymentId, $signature);

            if ($verified) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment) {
                    // Delegate to the central capture path. It flips
                    // payment status under a row lock, dispatches
                    // donation.confirmed, and runs Generate80GReceipt —
                    // all in one place. Earlier the web flow did a
                    // partial capture inline that skipped the
                    // donation.confirmed notification entirely.
                    app(\App\Services\PaymentCaptureService::class)->markCaptured(
                        $payment,
                        $paymentId,
                    );

                    $donation = Donation::where('payment_id', $payment->id)
                        ->with('receipt', 'donationType')
                        ->first();
                }
            }
        }

        // Item 3.2C — for a session that arrived through the app handoff,
        // hand the layout's return-to-app card a deep link that points at
        // the screen the app came from, enriched with the donation that
        // just completed. session()->now() keeps it to THIS request only:
        // it must never survive into a later page view (or a reload after
        // a fresh, unrelated visit) and it must never be written into a
        // cacheable guest response — from_app only ever exists on an
        // authenticated session, which bypasses CacheGuestResponse.
        if ($request->session()->has('from_app')) {
            $intent = $request->session()->get('from_app_intent', 'home');
            $extra = [];

            if ($donation !== null) {
                $extra['donation'] = (string) $donation->id;

                // No explicit return screen (older app builds post no
                // return_intent) but we do have a completed donation —
                // the receipt screen beats the home screen.
                if (! is_string($intent) || $intent === '' || $intent === 'home') {
                    $request->session()->put('from_app_intent', 'donate-thanks');
                }

                // Persist the id too, so if the devotee wanders off the
                // thank-you page the "browsed away" prompt still returns
                // them to this exact donation.
                $request->session()->put('from_app_intent_params', \App\Support\AppDeepLink::sanitizeParams(
                    array_merge((array) $request->session()->get('from_app_intent_params', []), $extra),
                ));
            }

            $request->session()->now('from_app_return_url', \App\Support\AppDeepLink::forSession($extra));
        }

        return view('pages.donation.thank-you', compact('verified', 'donation'));
    }

    public function greetingCard(string $donationId): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $donation = Donation::findOrFail($donationId);

        // Regenerate-if-missing: greeting cards live on r2_private with an
        // aggressive retention (1 day, hourly sweep) since they're
        // reproducible from the donation row + donation_type config. The
        // sweep NULLs greeting_card_path when it deletes the object, so a
        // non-null path means the file is present — no R2 ->exists() probe
        // (S3 HEADs from Hostinger hang).
        // needsRegeneration() also fires when the cached card was rendered
        // in a language the devotee has since changed away from — card
        // paths carry a `-{locale}` suffix for exactly this comparison.
        if (app(\App\Services\GreetingCardService::class)->needsRegeneration($donation)) {
            $regeneratedPath = app(\App\Services\GreetingCardService::class)->generate($donation);
            $donation->refresh();
            // generate() returns null when the donation_type has no
            // template configured at all — that's not a missing-file
            // situation, it's "no card was ever supposed to exist for
            // this donation type". 404 is the right response, but only when
            // there is no previously rendered card to fall back on: a
            // template removed after the fact must not 404 an old card.
            if (! $regeneratedPath && empty($donation->greeting_card_path)) {
                abort(404);
            }
        }

        // Redirect to a presigned R2 URL (inline image/png) so the card
        // streams straight from storage instead of through PHP. A fresh
        // presign is minted on every hit, so this permanent public route
        // keeps working as the greeting_card_url in notifications and shares.
        return private_file_redirect($donation->greeting_card_path, null, inline: true, contentType: 'image/png');
    }
}
