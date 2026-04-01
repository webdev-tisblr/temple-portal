<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\DevoteeResource;
use App\Models\Devotee;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
