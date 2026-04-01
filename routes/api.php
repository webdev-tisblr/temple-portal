<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SevaController;
use App\Http\Resources\DevoteeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Content (public)
    Route::get('/content/announcements', [ContentController::class, 'announcements']);
    Route::get('/content/live-darshan', [ContentController::class, 'liveDarshan']);
    Route::get('/campaigns', [ContentController::class, 'campaigns']);
    Route::get('/campaigns/{campaign}', [ContentController::class, 'campaignDetail']);

    // Public: Sevas
    Route::get('/sevas', [SevaController::class, 'index']);
    Route::get('/sevas/{seva}', [SevaController::class, 'show']);
    Route::get('/sevas/{seva}/slots', [SevaController::class, 'availableSlots']);

    // Webhooks (no auth)
    Route::post('/webhooks/razorpay', [PaymentWebhookController::class, 'handle']);

    // Public auth routes
    Route::post('/auth/otp/send', [AuthController::class, 'sendOtp']);
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refreshToken']);

        Route::get('/me', function (Request $request) {
            return new DevoteeResource($request->user());
        });

        Route::put('/me', function (Request $request) {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|nullable|email|max:255',
                'city' => 'sometimes|nullable|string|max:100',
                'state' => 'sometimes|nullable|string|max:100',
                'pincode' => 'sometimes|nullable|string|max:10',
                'date_of_birth' => 'sometimes|nullable|date',
                'language' => 'sometimes|in:gu,hi,en',
            ]);
            $request->user()->update($validated);
            return new DevoteeResource($request->user()->fresh());
        });

        // Seva booking (requires auth)
        Route::post('/sevas/{seva}/book', [SevaController::class, 'book']);

        // Donations
        Route::post('/donations', [DonationController::class, 'create']);
        Route::get('/donations/history', [DonationController::class, 'history']);
        Route::get('/donations/{donation}', [DonationController::class, 'show']);
        Route::get('/donations/{donation}/receipt', [DonationController::class, 'downloadReceipt']);
    });
});
