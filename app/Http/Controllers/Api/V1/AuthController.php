<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\DevoteeResource;
use App\Models\Devotee;
use App\Models\WebLoginToken;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends BaseApiController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        try {
            $code = $this->otpService->generate($phone);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {
            return $this->error($e->getMessage(), 429);
        }

        $response = ['message' => 'OTP sent successfully'];

        if (app()->environment('local', 'development', 'testing')) {
            $response['dev_otp'] = $code;
        }

        return $this->success($response, 'OTP sent successfully');
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $isValid = $this->otpService->verify($validated['phone'], $validated['code']);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {
            return $this->error($e->getMessage(), 429);
        }

        if (!$isValid) {
            return $this->error('Invalid or expired OTP', 401);
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

        $token = $devotee->createToken('mobile-app')->plainTextToken;

        return $this->success([
            'devotee' => new DevoteeResource($devotee),
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully');
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $token = $request->user()->createToken('mobile-app')->plainTextToken;

        return $this->success([
            'token' => $token,
        ], 'Token refreshed successfully');
    }

    /**
     * App→web login handoff. Issues a single-use, short-lived URL that
     * logs this devotee into a website session and lands on redirect_to.
     * Exists for the iOS donate flow (App Store 3.2.2(iv) forces the
     * donation itself onto the website) so the devotee doesn't face a
     * second OTP login in the browser.
     *
     * redirect_to is validated against an allowlist of internal paths and
     * stored server-side — the /auth/app-login consumer never reads a
     * redirect from the URL, so the token can't become an open redirect.
     */
    public function webSessionToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'redirect_to' => ['nullable', 'string', 'max:255'],
        ]);

        $redirect = $validated['redirect_to'] ?? '/donate';

        $allowedPrefixes = ['/donate', '/dashboard'];
        $isAllowed = str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
            && !str_contains($redirect, '\\')
            && collect($allowedPrefixes)->contains(
                fn (string $prefix) => $redirect === $prefix || str_starts_with($redirect, $prefix . '/') || str_starts_with($redirect, $prefix . '?'),
            );

        if (!$isAllowed) {
            $redirect = '/donate';
        }

        $devotee = $request->user();

        // One live token per devotee — a fresh request supersedes any
        // unspent one (the app retries on flaky networks).
        WebLoginToken::where('devotee_id', $devotee->id)->whereNull('used_at')->delete();

        $plain = Str::random(64);

        WebLoginToken::create([
            'devotee_id' => $devotee->id,
            'token_hash' => hash('sha256', $plain),
            'redirect_to' => $redirect,
            'expires_at' => now()->addMinutes(2),
            'created_at' => now(),
        ]);

        return $this->success([
            'url' => route('auth.app-login', ['token' => $plain]),
            'expires_in' => 120,
        ], 'Web session link created');
    }
}
