<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Devotee;
use App\Models\OtpCode;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class OtpService
{
    public function generate(string $phone, string $purpose = 'login'): string
    {
        if ($this->isLockedOut($phone)) {
            throw new TooManyRequestsHttpException(
                900,
                'Too many OTP attempts. Please try again after 15 minutes.'
            );
        }

        OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();

        // Real random 6-digit code via the OS CSPRNG, every environment.
        // No hardcoded bypass: local dev now reads the OTP from the log
        // line below (or whichever admin-enabled notification template
        // delivers it — WhatsApp / email / SMS once configured).
        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'phone' => $phone,
            'code' => $code,
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        Log::info("OTP for {$phone}: {$code}");

        // Look up an existing devotee by phone so the dispatcher can use
        // the 'devotee' recipient strategy (email/phone off the model) for
        // returning logins. First-time logins won't have a devotee yet —
        // that's fine, the context_path → phone strategy still works.
        $devotee = Devotee::where('phone', $phone)->first();

        // Route the OTP send through the central notification dispatcher.
        // Every enabled NotificationTemplate for 'auth.otp' fires — nothing
        // sends unless the admin has explicitly created and enabled a
        // template row for the channel they want (WhatsApp / email / SMS).
        app(NotificationService::class)->dispatch('auth.otp', [
            'phone' => $phone,
            'otp' => $code,
            'expires_in_minutes' => 10,
            'devotee' => $devotee,
            'email' => $devotee?->email,
            'name' => $devotee?->name,
        ]);

        return $code;
    }

    public function verify(string $phone, string $code, string $purpose = 'login'): bool
    {
        if ($this->isLockedOut($phone)) {
            throw new TooManyRequestsHttpException(
                900,
                'Too many OTP attempts. Please try again after 15 minutes.'
            );
        }

        $otp = OtpCode::where('phone', $phone)
            ->where('code', $code)
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->whereNull('verified_at')
            ->first();

        if (!$otp) {
            $latestOtp = OtpCode::where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->latest('created_at')
                ->first();

            if ($latestOtp) {
                $latestOtp->increment('attempts');
            }

            return false;
        }

        $otp->update(['verified_at' => now()]);

        return true;
    }

    public function isLockedOut(string $phone): bool
    {
        $latestOtp = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subMinutes(15))
            ->whereNull('verified_at')
            ->latest('created_at')
            ->first();

        return $latestOtp && $latestOtp->attempts >= 5;
    }

    public function cleanup(): void
    {
        OtpCode::where('created_at', '<', now()->subDay())->delete();
    }
}
