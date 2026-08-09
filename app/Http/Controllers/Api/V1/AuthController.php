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
            // 422, NOT 401. This endpoint is unauthenticated and a wrong or
            // already-used code is a validation failure, not a dead session.
            // Answering 401 made the app's Dio interceptor delete the stored
            // bearer token, so a devotee who was signed in got silently
            // logged out and every authenticated call afterwards failed with
            // "Unauthenticated" (account deletion was how we found it). The
            // client now guards this too, but keeping the status honest is
            // what protects app versions already out in the field.
            return $this->error('Invalid or expired OTP', 422);
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

        // Single active login on the APP surface: this fresh login
        // invalidates every other phone's token before the new one is
        // minted, and leaves the devotee's website sessions alone
        // (2026-08-09 — the two surfaces coexist; see
        // Devotee::revokeOtherLogins). (refreshToken() deliberately does
        // NOT do this — it only rotates its own token.)
        $devotee->revokeOtherLogins(Devotee::SCOPE_APP);

        // The token NAME is the surface discriminator a web login filters
        // on — it must stay Devotee::APP_TOKEN_NAME.
        $token = $devotee->createToken(Devotee::APP_TOKEN_NAME)->plainTextToken;

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

        $token = $request->user()->createToken(Devotee::APP_TOKEN_NAME)->plainTextToken;

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
            // Where the app should be returned to when the browser
            // errand finishes (item 3.2). Same vocabulary DeepLinkRouter
            // uses for FCM pushes; anything unknown falls back to 'home'.
            'return_intent' => ['nullable', 'string', 'max:48'],
            'return_intent_params' => ['nullable', 'array'],
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

        // Allowlisted here rather than at render time so a bad value can
        // never reach a session, and so the app gets a predictable link.
        $returnIntent = $validated['return_intent'] ?? null;
        $returnIntent = \App\Support\AppDeepLink::isValidIntent($returnIntent) ? $returnIntent : null;
        $returnParams = \App\Support\AppDeepLink::sanitizeParams($validated['return_intent_params'] ?? []);

        $devotee = $request->user();

        // One live token per devotee — a fresh request supersedes any
        // unspent one (the app retries on flaky networks).
        WebLoginToken::where('devotee_id', $devotee->id)->whereNull('used_at')->delete();

        $plain = Str::random(64);

        WebLoginToken::create([
            'devotee_id' => $devotee->id,
            'token_hash' => hash('sha256', $plain),
            'redirect_to' => $redirect,
            'return_intent' => $returnIntent,
            'return_intent_params' => $returnParams === [] ? null : $returnParams,
            'expires_at' => now()->addMinutes(2),
            'created_at' => now(),
        ]);

        return $this->success([
            'url' => route('auth.app-login', ['token' => $plain]),
            'expires_in' => 120,
        ], 'Web session link created');
    }
}
