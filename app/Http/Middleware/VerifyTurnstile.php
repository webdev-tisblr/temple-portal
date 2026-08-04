<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Turnstile server-side verification for public web forms.
 *
 * INERT until the admin pastes both keys in System Settings → Cloudflare
 * Turnstile — with no secret configured every request passes, so deploys
 * are safe before the Cloudflare widget exists. The matching client-side
 * widget is <x-turnstile /> (renders nothing while unconfigured).
 *
 * Fail-open on Cloudflare outages: a siteverify network error logs and
 * lets the request through — a devotee's contact message or OTP login
 * must not be lost to a third-party hiccup. Wrong/missing tokens (the
 * bot case) still fail closed.
 */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) SystemSetting::getValue('turnstile_secret_key', '');

        if ($secret === '') {
            return $next($request); // feature not configured
        }

        $token = (string) $request->input('cf-turnstile-response', '');

        if ($token === '') {
            return $this->reject($request);
        }

        try {
            $result = Http::asForm()->timeout(8)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ],
            )->json();
        } catch (\Throwable $e) {
            Log::warning('Turnstile siteverify unreachable — failing open', [
                'error' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if (($result['success'] ?? false) !== true) {
            Log::info('Turnstile rejected a submission', [
                'codes' => $result['error-codes'] ?? [],
                'path' => $request->path(),
            ]);

            return $this->reject($request);
        }

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        $message = match (app()->getLocale()) {
            'hi' => 'सत्यापन विफल रहा। कृपया पुनः प्रयास करें।',
            'en' => 'Verification failed. Please try again.',
            default => 'ચકાસણી નિષ્ફળ ગઈ. કૃપા કરીને ફરી પ્રયાસ કરો.',
        };

        return back()->withErrors(['turnstile' => $message])->withInput();
    }
}
