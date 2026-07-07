<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\HallController;
use App\Http\Controllers\Api\V1\PaymentVerificationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SevaController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Resources\DevoteeResource;
use App\Services\PanValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    // Lightweight public config endpoint for the Flutter app to gate old
    // versions (force-update). No auth: the app needs this before login.
    // Values are admin-editable via SystemSetting; sensible defaults apply
    // when unset so an unconfigured install never reports update_required.
    Route::get('/app-config', function () {
        $minVersion = \App\Models\SystemSetting::getValue('app_min_version', '1.0.0');
        $latestVersion = \App\Models\SystemSetting::getValue('app_latest_version', $minVersion);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'min_supported_version' => $minVersion,
                'latest_version' => $latestVersion,
                'android_store_url' => \App\Models\SystemSetting::getValue(
                    'app_android_store_url',
                    'https://play.google.com/store/apps/details?id=com.patadiyahanumanji.app',
                ),
                'ios_store_url' => \App\Models\SystemSetting::getValue(
                    'app_ios_store_url',
                    'https://apps.apple.com/app/patadiya-hanumanji',
                ),
                // Advisory flag: the server can force an update for ALL
                // current callers by setting app_update_required=1. The
                // app should still compare its own build against
                // min_supported_version for the authoritative gate.
                'update_required' => \App\Models\SystemSetting::getValue('app_update_required', '0') === '1',
            ],
        ]);
    });

    // Content (public)
    Route::get('/content/announcements', [ContentController::class, 'announcements']);
    Route::get('/content/live-darshan', [ContentController::class, 'liveDarshan']);
    Route::get('/content/darshan-timings', [ContentController::class, 'darshanTimings']);
    Route::get('/content/daily-darshan-photo', [ContentController::class, 'dailyDarshanPhoto']);
    // Personalised share card. Route is public; the controller calls
    // auth('sanctum')->user() to OPTIONALLY pick up the bearer token —
    // a logged-in devotee gets a personalised card, anonymous callers
    // get the generic one.
    Route::post('/content/daily-darshan-card', [ContentController::class, 'dailyDarshanShareCard']);
    Route::get('/content/temple-info', [ContentController::class, 'templeInfo']);
    Route::get('/content/trustees', [ContentController::class, 'trustees']);
    Route::get('/campaigns', [ContentController::class, 'campaigns']);
    Route::get('/campaigns/{campaign}', [ContentController::class, 'campaignDetail']);

    // Public: Sevas
    Route::get('/sevas', [SevaController::class, 'index']);
    Route::get('/sevas/{seva}', [SevaController::class, 'show']);
    Route::get('/sevas/{seva}/slots', [SevaController::class, 'availableSlots']);
    Route::get('/sevas/{seva}/available-dates', [SevaController::class, 'availableDates']);

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
    // Tighter throttle: contact form is a spam/abuse target.
    Route::post('/contact', [ContentController::class, 'submitContact'])->middleware('throttle:10,1');
    Route::get('/donation-types', [ContentController::class, 'donationTypes']);

    // Webhooks (no auth)
    // The Razorpay webhook must never be throttled — Razorpay retries on
    // any non-2xx and bursts of legitimate events (settlements, refunds)
    // can exceed the general cap. Exempt it from the group throttle.
    Route::post('/webhooks/razorpay', [PaymentWebhookController::class, 'handle'])
        ->withoutMiddleware('throttle:60,1');
    // WhatsApp delivery webhook. POST is the live event stream from
    // Meta Cloud API (relayed by "The Internet Store" BSP) — sent /
    // delivered / read / failed status events match against
    // notification_logs.provider_message_id and propagate the real
    // delivery state up. GET handles Meta's hub.challenge verification
    // handshake if/when the BSP forwards it during setup.
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);

    // Public auth routes — tighter throttle on the OTP surface. OtpService
    // also enforces a per-phone send cap; this is the per-IP layer.
    Route::post('/auth/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:10,1');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    // Device tokens — public registration so anonymous installs (no OTP
    // login yet) can still receive admin broadcasts. Auth-optional: if a
    // Sanctum token is present, the row is attached to that devotee.
    Route::post('/device-tokens', [DeviceTokenController::class, 'registerPublic']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refreshToken']);

        // Device tokens — legacy auth-required register (still used by the
        // older app version) and deactivate-on-logout.
        Route::post('/me/device-tokens', [DeviceTokenController::class, 'register']);
        Route::delete('/me/device-tokens', [DeviceTokenController::class, 'deactivate']);

        // Notification inbox — paginated history of broadcast pushes
        // visible to this devotee, with per-devotee read state.
        Route::get('/me/notifications', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'index']);
        Route::get('/me/notifications/unread-count', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'unreadCount']);
        Route::post('/me/notifications/read-all', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'markAllRead']);
        Route::post('/me/notifications/{notification}/read', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'markRead']);
        // Soft-dismiss — hides from inbox without affecting the underlying broadcast.
        Route::delete('/me/notifications/{notification}', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'dismiss']);
        Route::delete('/me/notifications', [\App\Http\Controllers\Api\V1\NotificationInboxController::class, 'dismissAll']);

        Route::get('/me', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => (new DevoteeResource($request->user()))->toArray($request),
            ]);
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
            $fresh = $request->user()->fresh();
            return response()->json([
                'success' => true,
                'message' => 'Profile updated',
                'data' => (new DevoteeResource($fresh))->toArray($request),
            ]);
        });

        Route::post('/me/photo', function (Request $request) {
            $request->validate([
                'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            $path = $request->file('photo')->store('profile-photos', 'r2');
            $request->user()->update(['profile_photo_path' => $path]);
            $fresh = $request->user()->fresh();

            return response()->json([
                'success' => true,
                'message' => 'Photo updated',
                'data' => (new DevoteeResource($fresh))->toArray($request),
            ]);
        });

        // Permanent account deletion (App Store 5.1.1(v) + Play User Data
        // policy). Erases PII; retains anonymised financial records.
        Route::delete('/me', [AccountController::class, 'destroy']);

        // Seva booking (requires auth) — creates a Razorpay order, so it
        // gets the same tighter CREATE throttle as donations/store orders.
        Route::post('/sevas/{seva}/book', [SevaController::class, 'book'])->middleware('throttle:10,1');
        Route::get('/bookings', [SevaController::class, 'bookings']);

        // Payment verification — called by app right after Razorpay success.
        // Confirms the payment server-side without waiting for the webhook.
        Route::post('/payments/verify', [PaymentVerificationController::class, 'verify']);

        // Donations
        // Tighter throttle on order CREATE: limits Razorpay order spam.
        Route::post('/donations', [DonationController::class, 'create'])->middleware('throttle:10,1');
        Route::get('/donations/history', [DonationController::class, 'history']);
        Route::get('/donations/{donation}', [DonationController::class, 'show']);
        Route::get('/donations/{donation}/receipt', [DonationController::class, 'downloadReceipt']);

        // Store (auth)
        // Tighter throttle on order CREATE: limits Razorpay order spam.
        Route::post('/store/orders', [StoreController::class, 'createOrder'])->middleware('throttle:10,1');
        Route::get('/store/orders', [StoreController::class, 'orders']);
        Route::get('/store/orders/{order}/invoice', [StoreController::class, 'downloadInvoice']);

        // Halls (auth)
        Route::post('/halls/{hall}/book', [HallController::class, 'book']);
        Route::get('/hall-bookings', [HallController::class, 'myBookings']);
        Route::get('/hall-bookings/{booking}/invoice', [HallController::class, 'downloadInvoice']);
    });
});
