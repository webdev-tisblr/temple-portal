<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Devotee;
use App\Services\OtpService;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthWebController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function showLogin(Request $request): View
    {
        // A CLICKED login link ("Login to book this seva") is not a guest
        // bounce, so Laravel never wrote session('url.intended') for it.
        // login_url() puts the origin page in ?next=; record it here so
        // verifyOtp() can honour it exactly like a bounced request.
        // Anything that fails SafeRedirect's rule is dropped silently and
        // the devotee simply lands on the dashboard.
        //
        // This also survives the two OTP POSTs: sendOtp() answers back()
        // to /login?next=…, which re-enters this method.
        SafeRedirect::remember($request->query('next'));

        return view('auth.login');
    }

    public function sendOtp(SendOtpRequest $request): RedirectResponse
    {
        $phone = $request->validated('phone');

        try {
            $code = $this->otpService->generate($phone);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {
            return back()->withErrors(['phone' => $e->getMessage()]);
        }

        $devMessage = app()->environment('local', 'development', 'testing')
            ? " (Dev OTP: {$code})"
            : '';

        return back()
            ->with('otp_sent', true)
            ->with('phone', $phone)
            ->with('success', "OTP મોકલવામાં આવ્યો છે.{$devMessage}");
    }

    public function verifyOtp(VerifyOtpRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $isValid = $this->otpService->verify($validated['phone'], $validated['code']);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if (!$isValid) {
            return back()
                ->with('otp_sent', true)
                ->with('phone', $validated['phone'])
                ->withErrors(['code' => 'ખોટો અથવા સમય વીતી ગયેલો OTP.']);
        }

        // Devotee::resolveForLogin handles the soft-delete tombstone
        // problem — see the doc-comment on the model method. Stamps
        // phone_verified_at + last_login_at internally.
        [$devotee, $wasNew] = Devotee::resolveForLogin($validated['phone']);

        if ($wasNew) {
            app(\App\Services\Notifications\NotificationService::class)->dispatch(
                'devotee.registered',
                ['devotee' => $devotee],
                idempotencyKey: "devotee:{$devotee->id}:registered",
            );
        }

        // Single active login PER SURFACE (2026-08-09). This used to be
        // a global revoke, which deleted the phone's Sanctum token too —
        // and because iOS donations are forced onto the website, every
        // donation logged the devotee out of the app ("the app randomly
        // ends my session", spec 07 suspect #2). Web now evicts only
        // other WEB sessions (auth_epoch); the app's token and its FCM
        // registration are left alone.
        $devotee->revokeOtherLogins(Devotee::SCOPE_WEB);

        Auth::guard('devotee')->login($devotee);

        $request->session()->regenerate();

        // Stamp this session with the epoch it was born under —
        // EnsureSingleDevoteeSession compares it on every request.
        $request->session()->put('devotee_auth_epoch', $devotee->auth_epoch);

        // Apply the devotee's saved language preference to the site so it
        // follows them across devices (app and web share devotee.language).
        if ($devotee->language !== null) {
            cookie()->queue(cookie('locale', $devotee->language->value, 60 * 24 * 365, null, null, null, false));
        }

        // New user or incomplete profile → force profile completion.
        // Deliberately does NOT consume the intended URL: the profile
        // form is an interstitial, and saveCompleteProfile() calls
        // intended() itself, so the chain
        // "protected page → login → profile → protected page" holds.
        if (empty($devotee->name)) {
            return redirect()->route('profile.complete');
        }

        // Back to whatever the devotee was actually trying to reach —
        // either the URL Laravel stored when it bounced them here, or the
        // ?next= a clicked "log in to continue" link carried (item 3.1).
        return redirect()->to(SafeRedirect::intended(route('dashboard.index')));
    }

    /**
     * Consume a single-use app→web handoff token (issued by
     * POST /api/v1/auth/web-session-token) and open a devotee session.
     * Used by the iOS app's donate flow: the donation must happen on the
     * website (App Store 3.2.2(iv)), but the devotee should arrive here
     * already logged in instead of facing a second OTP login.
     *
     * Any invalid/expired/reused token degrades silently to the guest
     * donate page, which carries its own login link.
     */
    public function appLogin(Request $request): RedirectResponse
    {
        $plain = (string) $request->query('token', '');

        if ($plain === '') {
            return redirect()->route('donate');
        }

        $token = \App\Models\WebLoginToken::where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->whereNull('used_at')
            ->first();

        // The guarded update makes consumption atomic — two racing
        // requests with the same link can't both log in.
        $claimed = $token !== null && \App\Models\WebLoginToken::where('id', $token->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;

        if (!$claimed) {
            return redirect()->route('donate');
        }

        $devotee = $token->devotee;

        if ($devotee === null) {
            return redirect()->route('donate');
        }

        // Same login lineage as the app that issued the handoff token —
        // deliberately NO revokeOtherLogins() here (it would log the app
        // out of its own session mid-donate). The session is stamped with
        // the CURRENT epoch so it stays valid exactly as long as the
        // app's login does.
        Auth::guard('devotee')->login($devotee);

        $request->session()->regenerate();

        $request->session()->put('devotee_auth_epoch', $devotee->auth_epoch);

        // Mark this session as originating from the mobile app so the
        // "return to the app" banner can show while the devotee browses
        // beyond the handoff destination. Must be set AFTER regenerate().
        $request->session()->put('from_app', now()->toIso8601String());

        // …and remember WHICH app screen launched the handoff, so the
        // "back to the app" prompts can return the devotee there instead
        // of dumping everyone on /home (item 3.2C). Both values were
        // allowlisted at token-mint time.
        $request->session()->put(
            'from_app_intent',
            \App\Support\AppDeepLink::isValidIntent($token->return_intent) ? $token->return_intent : 'home',
        );
        $request->session()->put(
            'from_app_intent_params',
            \App\Support\AppDeepLink::sanitizeParams($token->return_intent_params ?? []),
        );

        // Same language carry-over as the OTP login above.
        if ($devotee->language !== null) {
            cookie()->queue(cookie('locale', $devotee->language->value, 60 * 24 * 365, null, null, null, false));
        }

        // redirect_to is the server-stored, allowlisted path from token
        // creation — never attacker-controllable via this URL.
        return redirect()->to($token->redirect_to ?: route('donate'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('devotee')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
