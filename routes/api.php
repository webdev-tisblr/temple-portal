<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\HallController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SevaController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Resources\DevoteeResource;
use App\Services\PanValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Content (public)
    Route::get('/content/announcements', [ContentController::class, 'announcements']);
    Route::get('/content/live-darshan', [ContentController::class, 'liveDarshan']);
    Route::get('/content/darshan-timings', [ContentController::class, 'darshanTimings']);
    Route::get('/content/daily-darshan-photo', [ContentController::class, 'dailyDarshanPhoto']);
    Route::get('/content/temple-info', [ContentController::class, 'templeInfo']);
    Route::get('/campaigns', [ContentController::class, 'campaigns']);
    Route::get('/campaigns/{campaign}', [ContentController::class, 'campaignDetail']);

    // Public: Sevas
    Route::get('/sevas', [SevaController::class, 'index']);
    Route::get('/sevas/{seva}', [SevaController::class, 'show']);
    Route::get('/sevas/{seva}/slots', [SevaController::class, 'availableSlots']);

    // Public: Gallery & Events
    Route::get('/gallery', [ContentController::class, 'gallery']);
    Route::get('/events', [ContentController::class, 'events']);

    // Public: Store
    Route::get('/store/categories', [StoreController::class, 'categories']);
    Route::get('/store/products', [StoreController::class, 'products']);
    Route::get('/store/products/{product}', [StoreController::class, 'productDetail']);

    // Public: Halls
    Route::get('/halls', [HallController::class, 'index']);
    Route::get('/halls/{hall}/availability', [HallController::class, 'availability']);

    // Public: Blog
    Route::get('/blog', [ContentController::class, 'blog']);
    Route::get('/blog/{slug}', [ContentController::class, 'blogDetail']);

    // Public: Contact & Donation Types
    Route::post('/contact', [ContentController::class, 'submitContact']);
    Route::get('/donation-types', [ContentController::class, 'donationTypes']);

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
                'address' => 'sometimes|nullable|string|max:500',
                'city' => 'sometimes|nullable|string|max:100',
                'state' => 'sometimes|nullable|string|max:100',
                'pincode' => 'sometimes|nullable|string|max:10',
                'country' => 'sometimes|nullable|string|max:100',
                'date_of_birth' => 'sometimes|nullable|date',
                'language' => 'sometimes|in:gu,hi,en',
                'pan_number' => 'sometimes|nullable|string|size:10',
            ]);

            $updateData = collect($validated)->except(['pan_number'])->toArray();

            if (!empty($validated['pan_number'])) {
                $panService = app(PanValidationService::class);
                if (!$panService->validate($validated['pan_number'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid PAN format. Use format ABCDE1234F.',
                        'errors' => ['pan_number' => ['Invalid PAN format.']],
                    ], 422);
                }
                $updateData['pan_encrypted'] = Crypt::encryptString($validated['pan_number']);
                $updateData['pan_last_four'] = substr($validated['pan_number'], -4);
            }

            $request->user()->update($updateData);
            return new DevoteeResource($request->user()->fresh());
        });

        Route::post('/me/photo', function (Request $request) {
            $request->validate([
                'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            $path = $request->file('photo')->store('profile-photos', 'public');
            $request->user()->update(['profile_photo_path' => $path]);

            return new DevoteeResource($request->user()->fresh());
        });

        // Seva booking (requires auth)
        Route::post('/sevas/{seva}/book', [SevaController::class, 'book']);
        Route::get('/bookings', [SevaController::class, 'bookings']);

        // Donations
        Route::post('/donations', [DonationController::class, 'create']);
        Route::get('/donations/history', [DonationController::class, 'history']);
        Route::get('/donations/{donation}', [DonationController::class, 'show']);
        Route::get('/donations/{donation}/receipt', [DonationController::class, 'downloadReceipt']);

        // Store (auth)
        Route::post('/store/orders', [StoreController::class, 'createOrder']);
        Route::get('/store/orders', [StoreController::class, 'orders']);

        // Halls (auth)
        Route::post('/halls/{hall}/book', [HallController::class, 'book']);
        Route::get('/hall-bookings', [HallController::class, 'myBookings']);
    });
});
