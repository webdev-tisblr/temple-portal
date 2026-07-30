<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Devotee;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthWebController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function showLogin(): View
    {
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

        Auth::guard('devotee')->login($devotee);

        $request->session()->regenerate();

        // Apply the devotee's saved language preference to the site so it
        // follows them across devices (app and web share devotee.language).
        if ($devotee->language !== null) {
            cookie()->queue(cookie('locale', $devotee->language->value, 60 * 24 * 365, null, null, null, false));
        }

        // New user or incomplete profile → force profile completion
        if (empty($devotee->name)) {
            return redirect()->route('profile.complete');
        }

        return redirect()->route('dashboard.index');
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

        Auth::guard('devotee')->login($devotee);

        $request->session()->regenerate();

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
