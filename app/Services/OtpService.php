<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\OtpCode;
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

        // TODO: Remove hardcoded OTP before Play Store / App Store release
        $code = '123456';

        OtpCode::create([
            'phone' => $phone,
            'code' => $code,
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        Log::info("OTP for {$phone}: {$code}");

        // Send OTP via WhatsApp (skip in local — API onboarding in progress)
        if (! app()->environment('local')) {
            SendWhatsAppMessage::dispatch($phone, 'template', [
                'template_name' => 'otp_verification',
                'language_code' => 'en',
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $code],
                        ],
                    ],
                ],
            ]);
        }

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
