<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Devotee;
use App\Models\OtpCode;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * OTP generation + verification.
 *
 * Two distinct rate limits guard this surface:
 *
 *   1. **Verify lockout** — 5 failed verify attempts in 15 minutes locks
 *      both verify AND generation for the phone. Pre-existing behaviour.
 *
 *   2. **Send rate limit** (this addition) — caps how often we send a new
 *      OTP to the same phone:
 *
 *        • 1 send per 60 seconds (prevents the "user mashed Send again"
 *          loop from spamming SMS / WhatsApp)
 *        • 5 sends per hour (prevents an attacker rotating through the
 *          15-min lockout window to bomb a victim's phone via Send OTP)
 *
 *      The rate limiter uses RateLimiter::attempt with hash-keyed buckets;
 *      survives without Redis (uses cache.driver). Bypass: the limits do
 *      NOT trigger when an admin uses dispatchNow from Tinker.
 */
class OtpService
{
    private const SEND_LIMIT_PER_MINUTE = 1;
    private const SEND_LIMIT_PER_HOUR = 5;

    public function generate(string $phone, string $purpose = 'login'): string
    {
        if ($this->isLockedOut($phone)) {
            throw new TooManyRequestsHttpException(
                900,
                'Too many OTP attempts. Please try again after 15 minutes.'
            );
        }

        // Per-recipient send rate limit. Both buckets must clear; the
        // tighter (60s) one trips first under normal misuse.
        $this->enforceSendRateLimit($phone);

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

        // Only ever write the actual OTP code to the log in local/testing,
        // where there's no real delivery channel and the developer reads it
        // from the log. In production the code must NEVER hit the logs.
        if (app()->environment('local', 'testing')) {
            Log::info("OTP for {$phone}: {$code}");
        } else {
            // Non-sensitive observability line for production: confirms an
            // OTP was generated for a phone without leaking the code.
            Log::info('OTP generated', ['phone' => $phone, 'purpose' => $purpose]);
        }

        // Look up an existing devotee by phone so the dispatcher can use
        // the 'devotee' recipient strategy (email/phone off the model) for
        // returning logins. First-time logins won't have a devotee yet —
        // that's fine, the context_path → phone strategy still works.
        $devotee = Devotee::where('phone', $phone)->first();

        // Route the OTP send through the central notification dispatcher.
        // Every enabled NotificationTemplate for 'auth.otp' fires — nothing
        // sends unless the admin has explicitly created and enabled a
        // template row for the channel they want (WhatsApp / email / SMS).
        //
        // Idempotency key includes both phone and OTP code so a quick
        // retry from the same client with a fresh code still sends, but
        // an accidental double-tap that re-enters generate() with the
        // SAME code (cache race) doesn't fire two SMS.
        app(NotificationService::class)->dispatch(
            'auth.otp',
            [
                'phone' => $phone,
                'otp' => $code,
                'expires_in_minutes' => 10,
                'devotee' => $devotee,
                'email' => $devotee?->email,
                'name' => $devotee?->name,
            ],
            idempotencyKey: 'otp:' . sha1($phone . ':' . $code),
        );

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

    /**
     * Throws TooManyRequestsHttpException if EITHER the per-minute or
     * per-hour send cap is exceeded. Buckets are keyed by phone hash so
     * the cache key doesn't expose the raw phone number.
     */
    private function enforceSendRateLimit(string $phone): void
    {
        $phoneHash = sha1($phone);

        $minuteKey = "otp-send:1m:{$phoneHash}";
        if (RateLimiter::tooManyAttempts($minuteKey, self::SEND_LIMIT_PER_MINUTE)) {
            $retryAfter = RateLimiter::availableIn($minuteKey);
            throw new TooManyRequestsHttpException(
                $retryAfter,
                "Please wait {$retryAfter}s before requesting another OTP.",
            );
        }

        $hourKey = "otp-send:1h:{$phoneHash}";
        if (RateLimiter::tooManyAttempts($hourKey, self::SEND_LIMIT_PER_HOUR)) {
            $retryAfter = RateLimiter::availableIn($hourKey);
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Hourly OTP limit reached. Try again later.',
            );
        }

        // Increment AFTER both gates pass so a tripped longer-window
        // bucket doesn't also consume the short-window quota.
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);
    }
}
