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

        $devotee = Devotee::firstOrCreate(
            ['phone' => $validated['phone']],
            [
                'name' => '',
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ]
        );

        $devotee->update([
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        Auth::guard('devotee')->login($devotee);

        $request->session()->regenerate();

        return redirect()->route('dashboard.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('devotee')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
