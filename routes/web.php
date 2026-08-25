<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AuthWebController;
use App\Http\Controllers\Web\ContactController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DonationWebController;
use App\Http\Controllers\Web\EventWebController;
use App\Http\Controllers\Web\FacilityController;
use App\Http\Controllers\Web\GalleryWebController;
use App\Http\Controllers\Web\HallBookingController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\ReceiptLinkController;
use App\Http\Controllers\Web\SevaWebController;
use App\Http\Controllers\Web\StoreWebController;
use App\Http\Controllers\Web\TempleController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

// Deep health check for uptime monitoring. Framework /up only proves PHP
// boots; this proves MySQL and Redis actually answer. Path stays OUTSIDE the
// Cloudflare cache-rule whitelist and sends no-store, so a monitor pointed
// here always sees the origin's real state — never an edge-cached copy
// (Cloudflare's stale-on-error masked the 2026-07-28 DB outage from
// UptimeRobot for 5 hours).
Route::get('/up/deep', function () {
    $checks = [];
    try {
        DB::select('select 1');
        $checks['mysql'] = 'ok';
    } catch (Throwable) {
        $checks['mysql'] = 'fail';
    }
    try {
        Redis::connection()->ping();
        $checks['redis'] = 'ok';
    } catch (Throwable) {
        $checks['redis'] = 'fail';
    }
    $healthy = ! in_array('fail', $checks, true);

    return response()->json(['status' => $healthy ? 'ok' : 'fail'] + $checks, $healthy ? 200 : 500)
        ->header('Cache-Control', 'no-store');
})->name('health.deep');

// Native-app association files (item 3.2). Served from routes rather than
// static files in public/ so they can stay 404 until the Apple Team ID /
// Android signing fingerprint are actually known — a published association
// with a placeholder id gets cached by Apple's CDN and is worse than none.
// Both must be plain JSON with no redirect: Apple's fetcher follows neither.
Route::get('/.well-known/apple-app-site-association', function () {
    $teamId = config('deep_links.apple_team_id');
    abort_if(blank($teamId), 404);

    return response()->json([
        'applinks' => [
            'apps' => [],
            'details' => [[
                'appID' => $teamId.'.'.config('deep_links.bundle_id'),
                'paths' => config('deep_links.paths'),
            ]],
        ],
    ])->header('Content-Type', 'application/json')
        ->header('Cache-Control', 'public, max-age=3600');
})->name('wellknown.aasa');

Route::get('/.well-known/assetlinks.json', function () {
    $fingerprints = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) config('deep_links.android_sha256')),
    )));
    abort_if($fingerprints === [], 404);

    return response()->json([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => config('deep_links.bundle_id'),
            'sha256_cert_fingerprints' => $fingerprints,
        ],
    ]])->header('Content-Type', 'application/json')
        ->header('Cache-Control', 'public, max-age=3600');
})->name('wellknown.assetlinks');

// Language switch — sets the locale cookie server-side and bounces back to
// the page the user was on. Using a dedicated route (instead of a ?lang=
// query param) keeps public URLs clean and immune to full-page caches that
// key on the URL, so switching reliably re-renders in the chosen language.
Route::get('/locale/{locale}', function (string $locale) {
    if (! in_array($locale, ['gu', 'hi', 'en'], true)) {
        $locale = 'gu';
    }

    $back = url()->previous();
    // Only ever bounce back to a same-host page (guards against an open
    // redirect via a crafted Referer) and never into the switch route itself.
    if (parse_url($back, PHP_URL_HOST) !== request()->getHost() || str_contains($back, '/locale/')) {
        $back = url('/');
    }

    app()->setLocale($locale);

    // Logged-in devotees: persist the choice to their profile so it follows
    // them to the app and other devices.
    $devotee = auth('devotee')->user();
    if ($devotee !== null) {
        $devotee->update(['language' => $locale]);
    }

    return redirect($back)->withCookie(
        cookie('locale', $locale, 60 * 24 * 365, null, null, null, false)
    );
})->name('locale.set');

// Seva
Route::get('/seva', [SevaWebController::class, 'index'])->name('seva.index');
// MUST be registered before /seva/{seva} or the wildcard swallows it.
Route::get('/seva/greeting-card/{booking}', [SevaWebController::class, 'greetingCard'])->name('seva.greeting-card');
Route::get('/seva/{seva}', [SevaWebController::class, 'show'])->name('seva.show');

// Donation (public view)
Route::get('/donate', [DonationWebController::class, 'index'])->name('donate');
Route::get('/donate/thank-you', [DonationWebController::class, 'thankYou'])->name('donate.thanks');
Route::get('/donate/greeting-card/{donation}', [DonationWebController::class, 'greetingCard'])->name('donation.greeting-card');

// Temple info
Route::get('/darshan', [TempleController::class, 'darshan'])->name('darshan');
Route::get('/trustees', [TempleController::class, 'trustees'])->name('trustees');
Route::get('/rules', [TempleController::class, 'rules'])->name('rules');

// Events
Route::get('/events', [EventWebController::class, 'index'])->name('events.index');
Route::get('/events/{event}', [EventWebController::class, 'show'])->name('events.show');

// Gallery
Route::get('/gallery', [GalleryWebController::class, 'index'])->name('gallery');
Route::get('/gallery/{category}', [GalleryWebController::class, 'category'])->name('gallery.category');

// Facilities
Route::get('/bhojanalay', [FacilityController::class, 'bhojanalay'])->name('bhojanalay');
Route::get('/yatriwas', [FacilityController::class, 'yatriwas'])->name('yatriwas');
Route::get('/places-around', [FacilityController::class, 'placesAround'])->name('places');

// Blog module removed 2026-07 — permanent redirects for old links.
Route::redirect('/blog', '/', 301);
Route::redirect('/blog/{slug}', '/', 301);

// Contact
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
// Sending a message requires a login (2026-08-17): every submission is tied
// to a devotee, and its name/phone come from that profile. The GET page stays
// public — the trust's address/phone/email must remain readable by anyone.
Route::post('/contact', [ContactController::class, 'submit'])
    ->name('contact.submit')
    ->middleware(['auth:devotee', 'turnstile']);

// Permanent signed receipt/invoice links — embedded in confirmation
// messages (WhatsApp document headers, email bodies). Signature is the
// authorization; PDFs regenerate on miss, so links never expire.
// NOT in the CacheGuestResponse whitelist / Cloudflare cache rule.
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('/r/seva-receipt/{booking}', [ReceiptLinkController::class, 'sevaReceipt'])->name('seva.receipt.link');
    Route::get('/r/store-invoice/{order}', [ReceiptLinkController::class, 'storeInvoice'])->name('store.invoice.link');
    Route::get('/r/hall-invoice/{booking}', [ReceiptLinkController::class, 'hallInvoice'])->name('hall.invoice.link');
});

// Store (public)
Route::get('/store', [StoreWebController::class, 'index'])->name('store.index');
Route::get('/store/category/{slug}', [StoreWebController::class, 'category'])->name('store.category');
Route::get('/store/product/{slug}', [StoreWebController::class, 'show'])->name('store.product');

// Hall Booking (public)
// Halls listing — entry point shown in the menu.
Route::get('/halls', [HallBookingController::class, 'hallsList'])->name('halls.index');
// Per-hall booking page (gallery, details, form).
Route::get('/halls/{hall}', [HallBookingController::class, 'hallShow'])->name('halls.show');
// Legacy /hall-booking → halls listing (preserved as `hall.booking` so old
// URLs and existing route('hall.booking') call-sites stay valid).
Route::get('/hall-booking', fn () => redirect()->route('halls.index'))->name('hall.booking');
Route::get('/hall-booking/check', [HallBookingController::class, 'checkAvailability'])->name('hall.booking.check');
// Item 4.4 — "Next available" affordance on the website (parity with the app).
Route::get('/hall-booking/next-available', [HallBookingController::class, 'nextAvailable'])->name('hall.booking.next');

// Auth
Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
Route::post('/login/otp/send', [AuthWebController::class, 'sendOtp'])->name('login.otp.send')->middleware('turnstile');
Route::post('/login/otp/verify', [AuthWebController::class, 'verifyOtp'])->name('login.otp.verify');
Route::post('/logout', [AuthWebController::class, 'logout'])->name('logout');
// Typing /logout in the address bar is a GET, which used to 404 — the
// devotee asked to sign out and got a dead page instead. POST stays the
// real logout (it's the CSRF-protected one the header form submits);
// this just makes the URL do the obvious thing. Signing a guest "out"
// is a no-op redirect, so an <img src=".../logout"> CSRF only ever
// costs a session, never data.
Route::get('/logout', [AuthWebController::class, 'logoutViaLink'])->name('logout.link');
// App→web session handoff — consumes the single-use token minted by
// POST /api/v1/auth/web-session-token (iOS donate flow). Throttled: the
// token space is unguessable, but there's no reason to allow brute probing.
Route::get('/auth/app-login', [AuthWebController::class, 'appLogin'])
    ->middleware('throttle:10,1')
    ->name('auth.app-login');

// Authenticated devotee routes
Route::middleware('auth:devotee')->group(function () {
    // Profile completion (accessible even with incomplete profile)
    Route::get('/profile/complete', [DashboardController::class, 'showCompleteProfile'])->name('profile.complete');
    Route::post('/profile/complete', [DashboardController::class, 'saveCompleteProfile'])->name('profile.complete.save');

    // Payment callbacks (must work before profile check)
    Route::get('/seva/booking/success', [SevaWebController::class, 'bookingSuccess'])->name('seva.booking.success');
    Route::get('/seva/booking/failure', [SevaWebController::class, 'bookingFailure'])->name('seva.booking.failure');
    Route::get('/store/order/success', [StoreWebController::class, 'orderSuccess'])->name('store.order.success');
    Route::get('/store/order/failure', [StoreWebController::class, 'orderFailure'])->name('store.order.failure');
    Route::get('/hall-booking/success', [HallBookingController::class, 'bookingSuccess'])->name('hall.booking.success');
    Route::get('/hall-booking/failure', [HallBookingController::class, 'bookingFailure'])->name('hall.booking.failure');

    // Everything below requires a complete profile
    Route::middleware('profile.complete')->group(function () {
        // The four POSTs that open a Razorpay order. `payment.once` makes a
        // resent identical submission replay the first one's checkout page
        // rather than create a second order + orphan pending row — the
        // server half of the double-press fix (the browser half is
        // resources/js/submit-lock.js).
        Route::post('/donate', [DonationWebController::class, 'create'])
            ->middleware('payment.once')
            ->name('donate.create');
        Route::post('/seva/{seva}/book', [SevaWebController::class, 'book'])
            ->middleware('payment.once')
            ->name('seva.book');
        Route::post('/hall-booking/book', [HallBookingController::class, 'book'])
            ->middleware('payment.once')
            ->name('hall.booking.book');

        // Store (authenticated)
        Route::get('/store/cart', [StoreWebController::class, 'cart'])->name('store.cart');
        Route::post('/store/cart/add', [StoreWebController::class, 'addToCart'])->name('store.cart.add');
        // POST instead of PATCH/DELETE — some CDN/WAF rules silently drop
        // non-standard verbs from XHR, leaving the cart session out of sync
        // with the on-page Alpine state.
        Route::post('/store/cart/update', [StoreWebController::class, 'updateCart'])->name('store.cart.update');
        Route::post('/store/cart/remove', [StoreWebController::class, 'removeFromCart'])->name('store.cart.remove');
        Route::post('/store/checkout', [StoreWebController::class, 'checkout'])
            ->middleware('payment.once')
            ->name('store.checkout');
        Route::get('/store/order/{order}/invoice', [StoreWebController::class, 'downloadInvoice'])->name('store.order.invoice');
        Route::get('/hall-booking/{booking}/invoice', [HallBookingController::class, 'downloadInvoice'])->name('hall.booking.invoice');
        // Devotee-initiated cancellation REQUEST — the trust decides.
        Route::post('/hall-booking/{booking}/cancel-request', [HallBookingController::class, 'requestCancellation'])
            ->middleware('throttle:6,1')
            ->name('hall.booking.cancel-request');

        // Dashboard
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('index');
            Route::get('/donations', [DashboardController::class, 'donations'])->name('donations');
            Route::get('/bookings', [DashboardController::class, 'bookings'])->name('bookings');
            Route::get('/bookings/{booking}/receipt', [DashboardController::class, 'downloadBookingReceipt'])->name('bookings.receipt');
            Route::get('/orders', [DashboardController::class, 'orders'])->name('orders');
            Route::get('/receipts', [DashboardController::class, 'receipts'])->name('receipts');
            Route::get('/receipts/{receipt}/download', [DashboardController::class, 'downloadReceipt'])->name('receipts.download');
            Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
            Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
        });
    });
});

// Crowdfunding Projects (public)
Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/projects/{slug}', [ProjectController::class, 'show'])->name('projects.show');
Route::get('/projects/{slug}/donors', [ProjectController::class, 'donors'])->name('projects.donors');

// /file/{path} and /storage/{path} routes used to proxy uploaded files
// from storage/app/public on Hostinger (which blocks /storage at the
// server level). All uploads now live in Cloudflare R2 and are served
// through cdn.patadiyahanumanji.com via the image_url() helper, so the
// proxy is no longer reachable by any code path. Removed Phase 5.

// REMOVED 2026-08-09 (RBAC audit G14): GET /admin-tools/storage-repair.
// It ran mkdir/unlink/symlink against public/storage and printed absolute
// server paths behind nothing but `auth('admin')->check()` — no permission,
// no is_active check, no panel_user check. Living outside the Filament panel
// it bypassed AdminUser::canAccessPanel() entirely, so a DEACTIVATED admin
// with a live session cookie (or any volunteer) could execute it. It was
// also obsolete: the storage:migrate-uploads-to-public step was dropped in
// Phase 5 and every upload now lives in Cloudflare R2, served via
// cdn.patadiyahanumanji.com by the image_url() helper — nothing reads
// public/storage any more. Nothing referenced route('admin.storage-repair')
// anywhere in the repo, so deletion is safe. If a storage symlink is ever
// needed again the VPS has SSH: `ssh temple-vps` then `php artisan storage:link`.

// Legal / store-compliance pages (required by App Store + Google Play).
// Defined before the CMS catch-all so these fixed URLs always resolve.
Route::get('/privacy-policy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/refund-policy', [LegalController::class, 'refund'])->name('legal.refund');
Route::get('/account-deletion', [LegalController::class, 'accountDeletion'])->name('legal.account-deletion');

// Chrome-free CMS page for the mobile app WebView (must precede the catch-all).
Route::get('/pages/{slug}/embed', [PageController::class, 'embed'])->name('page.embed');

// CMS Pages (catch-all — MUST BE LAST)
Route::get('/{slug}', [PageController::class, 'show'])->name('page.show');
