<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DonationController;
use App\Http\Controllers\Api\V1\GuideController;
use App\Http\Controllers\Api\V1\HallController;
use App\Http\Controllers\Api\V1\Msg91WebhookController;
use App\Http\Controllers\Api\V1\NotificationInboxController;
use App\Http\Controllers\Api\V1\PaymentVerificationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SevaController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Resources\DevoteeResource;
use App\Models\SystemSetting;
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
        $minVersion = SystemSetting::getValue('app_min_version', '1.0.0');
        $latestVersion = SystemSetting::getValue('app_latest_version', $minVersion);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'min_supported_version' => $minVersion,
                'latest_version' => $latestVersion,
                'android_store_url' => SystemSetting::getValue(
                    'app_android_store_url',
                    'https://play.google.com/store/apps/details?id=com.patadiyahanumanji.app',
                ),
                'ios_store_url' => SystemSetting::getValue(
                    'app_ios_store_url',
                    // The real listing (id 6795803756). The old default here
                    // was a guessed slug with no app id, which 404s — and it
                    // is what the app's "Update available" prompt opens.
                    'https://apps.apple.com/in/app/shree-patadiya-hanumanji/id6795803756',
                ),
                // Advisory flag: the server can force an update for ALL
                // current callers by setting app_update_required=1. The
                // app should still compare its own build against
                // min_supported_version for the authoritative gate.
                'update_required' => SystemSetting::getValue('app_update_required', '0') === '1',
                // App Store guideline 3.2.2(iv): non-Benevity nonprofits may
                // not collect donations in-app on iOS — the app must send
                // devotees to the website instead. Default OFF; flip to 1
                // only after Apple confirms the trust's approved-nonprofit
                // status (Benevity), which re-enables the native iOS flow
                // without an app release. Android ignores this flag.
                'ios_native_donations_enabled' => SystemSetting::getValue('app_ios_native_donations', '0') === '1',
                'donate_web_url' => SystemSetting::getValue(
                    'app_donate_web_url',
                    'https://patadiyahanumanji.com/donate',
                ),
                // "Join our WhatsApp Group" row in the app's More screen —
                // hidden when the toggle is off or the URL is empty.
                'whatsapp_group_url' => SystemSetting::getValue('app_whatsapp_group_url', ''),
                'whatsapp_group_enabled' => SystemSetting::getValue('app_whatsapp_group_enabled', '0') === '1',
            ],
        ]);
    });

    // Content (public)
    // Legacy stub — Announcements removed 2026-07. Old app builds still
    // call this; drop once app_min_version >= the release without blog UI.
    Route::get('/content/announcements', fn () => response()->json(
        ['success' => true, 'message' => 'Success', 'data' => []]));
    Route::get('/content/app-strings', [ContentController::class, 'appStrings']);
    Route::get('/content/live-darshan', [ContentController::class, 'liveDarshan']);
    Route::get('/content/darshan-timings', [ContentController::class, 'darshanTimings']);
    Route::get('/content/daily-darshan-photo', [ContentController::class, 'dailyDarshanPhoto']);
    // Personalised share card. Route is public; the controller calls
    // auth('sanctum')->user() to OPTIONALLY pick up the bearer token —
    // a logged-in devotee gets a personalised card, anonymous callers
    // get the generic one.
    Route::post('/content/daily-darshan-card', [ContentController::class, 'dailyDarshanShareCard']);
    // Status maker — admin templates the devotee personalises on demand
    // (same auth-optional pattern as the darshan card above).
    Route::get('/content/status-templates', [ContentController::class, 'statusTemplates']);
    Route::post('/content/status-card', [ContentController::class, 'statusCard']);
    Route::get('/content/temple-info', [ContentController::class, 'templeInfo']);
    Route::get('/content/pages', [ContentController::class, 'pages']);
    Route::get('/content/trustees', [ContentController::class, 'trustees']);
    Route::get('/campaigns', [ContentController::class, 'campaigns']);
    Route::get('/campaigns/{campaign}', [ContentController::class, 'campaignDetail']);
    // Public donor list — the app mirror of /projects/{slug}/donors. Masking
    // and the captured-only filter live in \App\Support\CampaignDonors so the
    // two surfaces cannot drift.
    Route::get('/campaigns/{campaign}/donors', [CampaignController::class, 'donors']);

    // Public: Sevas
    Route::get('/sevas', [SevaController::class, 'index']);
    Route::get('/seva-categories', [SevaController::class, 'categories']);
    Route::get('/sevas/{seva}', [SevaController::class, 'show']);
    Route::get('/sevas/{seva}/slots', [SevaController::class, 'availableSlots']);
    Route::get('/sevas/{seva}/available-dates', [SevaController::class, 'availableDates']);
    // Item 4.4 — one request instead of the app's 12+N month scan.
    Route::get('/sevas/{seva}/next-available', [SevaController::class, 'nextAvailable']);

    // Public: Gallery & Events
    Route::get('/gallery', [ContentController::class, 'gallery']);
    Route::get('/gallery-categories', [ContentController::class, 'galleryCategories']);
    Route::get('/events', [ContentController::class, 'events']);

    // Public: User Guides / Help Center
    Route::get('/guides', [GuideController::class, 'index']);
    Route::get('/guides/{id}', [GuideController::class, 'show'])->whereNumber('id');

    // Public: Store
    Route::get('/store/categories', [StoreController::class, 'categories']);
    Route::get('/store/products', [StoreController::class, 'products']);
    Route::get('/store/products/{product}', [StoreController::class, 'productDetail']);

    // Public: Halls
    Route::get('/halls', [HallController::class, 'index']);
    Route::get('/halls/{hall}/availability', [HallController::class, 'availability']);
    Route::get('/halls/{hall}/available-dates', [HallController::class, 'availableDates']);
    // Item 4.2 — server-authoritative multi-day quote (never trust a
    // client-computed price). Item 4.4 — next open window.
    Route::get('/halls/{hall}/range-quote', [HallController::class, 'rangeQuote']);
    Route::get('/halls/{hall}/next-available', [HallController::class, 'nextAvailable']);

    // Legacy stubs — Blog removed 2026-07. Old app builds still call
    // these; drop once app_min_version >= the release without blog UI.
    Route::get('/blog', fn () => response()->json(
        ['success' => true, 'message' => 'Success',
            'data' => ['posts' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]]));
    Route::get('/blog/{slug}', fn () => response()->json(
        ['success' => false, 'message' => 'Post not found'], 404));

    // Contact form — AUTHENTICATED since 2026-08-17. Identity (name/phone/
    // email) is read from the token's devotee, so the request body carries
    // only category/subject/message. App builds older than that release post
    // unauthenticated and will get a 401; those builds predate the
    // login-first requirement and are expected to update.
    // The tight throttle stays — the form is still a spam/abuse target.
    Route::post('/contact', [ContentController::class, 'submitContact'])
        ->middleware(['auth:sanctum', 'api.profile.complete', 'throttle:10,1,contact']);
    Route::get('/contact-categories', [ContentController::class, 'contactCategories']);
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

    // MSG91 SMS delivery reports. Registered here for symmetry with the
    // other two; the canonical URL handed to the trust is the shorter
    // /api/webhooks/msg91/{token} declared below, outside the v1 prefix,
    // because it has to be retyped/pasted into MSG91's dashboard by hand.
    // Both paths reach the same controller, so a URL pasted from either
    // place keeps working.
    Route::match(['post', 'get'], '/webhooks/msg91/{token}', [Msg91WebhookController::class, 'handle'])
        ->withoutMiddleware('throttle:60,1')
        ->middleware('throttle:msg91-webhook');

    // ⚠ THE THIRD ARGUMENT IS LOAD-BEARING (2026-08-21).
    //
    // Laravel keys an unnamed `throttle:x,1` on sha1(user id) for an
    // authenticated caller — with NO per-route component. So every tight
    // per-route cap here shared ONE counter with the group's throttle:60,1,
    // and each of them incremented it. A devotee who had made ten API calls
    // in the past minute — which ordinary browsing does in seconds — got a
    // 429 on donate / seva book / store order before the request reached
    // the controller. The third argument is ThrottleRequests' $prefix; it
    // gives each cap its own bucket. Never drop it from a route that
    // carries a cap tighter than the group's.
    //
    // Public auth routes. The REAL abuse protection is OtpService's per-phone
    // cap (1 send/minute, 5/hour, plus a 5-failure verify lockout) — that is
    // keyed on the phone number, so it holds however many devotees share an
    // address. This per-IP layer is only a coarse backstop.
    //
    // Raised from 10/min after launch night: Indian mobile carriers put large
    // numbers of subscribers behind one public IP, so a shared address is a
    // crowd of ordinary devotees rather than an attacker. At 10 the cap was
    // rejecting real logins (851 sends + 640 verifies answered 429). Lowering
    // it again would not add protection the per-phone cap does not already
    // give.
    Route::post('/auth/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:40,1,otp-send');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:40,1,otp-verify');

    // Device tokens — public registration so anonymous installs (no OTP
    // login yet) can still receive admin broadcasts. Auth-optional: if a
    // Sanctum token is present, the row is attached to that devotee.
    Route::post('/device-tokens', [DeviceTokenController::class, 'registerPublic']);

    // Authenticated routes.
    //
    // Routes that MOVE MONEY or send a devotee-facing message additionally
    // carry 'api.profile.complete' (2026-08-21). Signup only verifies a
    // phone — the devotee row is created with an empty name — and until
    // that gate existed the app could transact from a nameless account,
    // which meant every WhatsApp confirmation for it was rejected by Meta
    // (empty template parameter) and never arrived. /payments/verify is
    // deliberately NOT gated: money has already moved by then.
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refreshToken']);
        // App→web login handoff (iOS donate flow) — returns a single-use
        // /auth/app-login URL. Tight throttle: one link per tap is plenty.
        Route::post('/auth/web-session-token', [AuthController::class, 'webSessionToken'])
            ->middleware(['api.profile.complete', 'throttle:10,1,web-handoff']);

        // Device tokens — legacy auth-required register (still used by the
        // older app version) and deactivate-on-logout.
        Route::post('/me/device-tokens', [DeviceTokenController::class, 'register']);
        Route::delete('/me/device-tokens', [DeviceTokenController::class, 'deactivate']);

        // Notification inbox — paginated history of broadcast pushes
        // visible to this devotee, with per-devotee read state.
        Route::get('/me/notifications', [NotificationInboxController::class, 'index']);
        Route::get('/me/notifications/unread-count', [NotificationInboxController::class, 'unreadCount']);
        Route::post('/me/notifications/read-all', [NotificationInboxController::class, 'markAllRead']);
        Route::post('/me/notifications/{notification}/read', [NotificationInboxController::class, 'markRead']);
        // Soft-dismiss — hides from inbox without affecting the underlying broadcast.
        Route::delete('/me/notifications/{notification}', [NotificationInboxController::class, 'dismiss']);
        Route::delete('/me/notifications', [NotificationInboxController::class, 'dismissAll']);

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
                'clear_pan' => 'sometimes|boolean',
            ]);

            $updateData = collect($validated)->except(['pan_number', 'clear_pan'])->toArray();

            // PAN stays OPTIONAL but is now REMOVABLE (item 5.4). Until this
            // change every path guarded on `! empty($pan_number)`, so a
            // devotee who had typed a WRONG PAN could never get rid of it —
            // and under the strict-80G rule a wrong PAN is worse than none,
            // because it goes onto a statutory document.
            //
            // Two accepted signals: an explicit `clear_pan` flag, or
            // `pan_number` sent explicitly blank/null. `size:10` above only
            // fires on a non-empty value, so a blank still validates.
            $wantsClear = ($validated['clear_pan'] ?? false)
                || ($request->has('pan_number') && blank($validated['pan_number'] ?? null));

            if ($wantsClear) {
                $updateData['pan_encrypted'] = null;
                $updateData['pan_last_four'] = null;
            } elseif (! empty($validated['pan_number'])) {
                $panService = app(PanValidationService::class);
                if (! $panService->validate($validated['pan_number'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid PAN format. Use format ABCDE1234F.',
                        'errors' => ['pan_number' => ['Invalid PAN format.']],
                    ], 422);
                }
                // Canonicalise to uppercase — validate() uppercases before
                // matching, so a lowercase PAN passes and would otherwise be
                // stored (and printed on the 80G receipt) as typed.
                $pan = strtoupper($validated['pan_number']);
                $updateData['pan_encrypted'] = Crypt::encryptString($pan);
                $updateData['pan_last_four'] = substr($pan, -4);
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
        Route::post('/sevas/{seva}/book', [SevaController::class, 'book'])
            ->middleware(['api.profile.complete', 'throttle:10,1,seva-book']);
        Route::get('/bookings', [SevaController::class, 'bookings']);
        Route::get('/bookings/{booking}/receipt', [SevaController::class, 'downloadReceipt']);

        // Payment verification — called by app right after Razorpay success.
        // Confirms the payment server-side without waiting for the webhook.
        Route::post('/payments/verify', [PaymentVerificationController::class, 'verify']);

        // Donations
        // Tighter throttle on order CREATE: limits Razorpay order spam.
        Route::post('/donations', [DonationController::class, 'create'])
            ->middleware(['api.profile.complete', 'throttle:10,1,donate']);
        Route::get('/donations/history', [DonationController::class, 'history']);
        Route::get('/donations/{donation}', [DonationController::class, 'show']);
        Route::get('/donations/{donation}/receipt', [DonationController::class, 'downloadReceipt']);

        // Store (auth)
        // Tighter throttle on order CREATE: limits Razorpay order spam.
        Route::post('/store/orders', [StoreController::class, 'createOrder'])
            ->middleware(['api.profile.complete', 'throttle:10,1,store-order']);
        Route::get('/store/orders', [StoreController::class, 'orders']);
        Route::get('/store/orders/{order}/invoice', [StoreController::class, 'downloadInvoice']);

        // Halls (auth)
        Route::post('/halls/{hall}/book', [HallController::class, 'book'])
            ->middleware('api.profile.complete');
        Route::get('/hall-bookings', [HallController::class, 'myBookings']);
        Route::get('/hall-bookings/{booking}/invoice', [HallController::class, 'downloadInvoice']);
        // A cancellation REQUEST — the trust approves it, nothing is
        // cancelled by this call. Throttled: it fires a notification.
        Route::post('/hall-bookings/{booking}/cancel-request', [HallController::class, 'requestCancellation'])
            ->middleware('throttle:6,1,hall-cancel');
    });
});

/*
|--------------------------------------------------------------------------
| MSG91 SMS delivery reports (canonical URL)
|--------------------------------------------------------------------------
|
| Deliberately OUTSIDE the /v1 prefix. This URL is not consumed by our own
| app — a human copies it out of the admin panel and pastes it into the
| MSG91 dashboard's delivery-report field, by hand, once. Every character
| that is not carrying meaning is a character they can mistype, and the
| path already carries a 48-character token.
|
| PROTECTION: the {token} segment IS the credential — MSG91 offers no
| signing secret, no HMAC and no custom header on its DLR callback, so a
| capability URL is the only option available. It is compared with
| hash_equals and is rotatable from System Settings → SMS. It is NOT
| authentication of MSG91: whoever holds the URL can POST to it. A forged
| report can only alter the delivery status displayed against a message
| that was already sent — it cannot trigger a send, read devotee data, or
| touch money. See Msg91WebhookController's class docblock.
|
| Throttled via the named `msg91-webhook` limiter (see AppServiceProvider)
| rather than the API group's 60/min: delivery reports arrive in bursts
| after a batch send, and a 429 would make MSG91 retry a report that was
| never the problem.
*/
Route::match(['post', 'get'], '/webhooks/msg91/{token}', [Msg91WebhookController::class, 'handle'])
    ->middleware('throttle:msg91-webhook')
    ->name('webhooks.msg91');
