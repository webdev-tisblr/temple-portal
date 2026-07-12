<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AuthWebController;
use App\Http\Controllers\Web\BlogController;
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
use App\Http\Controllers\Web\SevaWebController;
use App\Http\Controllers\Web\StoreWebController;
use App\Http\Controllers\Web\TempleController;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

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

    return redirect($back)->withCookie(
        cookie('locale', $locale, 60 * 24 * 365, null, null, null, false)
    );
})->name('locale.set');

// Seva
Route::get('/seva', [SevaWebController::class, 'index'])->name('seva.index');
Route::get('/seva/{seva}', [SevaWebController::class, 'show'])->name('seva.show');

// Donation (public view)
Route::get('/donate', [DonationWebController::class, 'index'])->name('donate');
Route::get('/donate/thank-you', [DonationWebController::class, 'thankYou'])->name('donate.thanks');
Route::get('/donate/greeting-card/{donation}', [DonationWebController::class, 'greetingCard'])->name('donation.greeting-card');

// Temple info
Route::get('/darshan', [TempleController::class, 'darshan'])->name('darshan');
Route::get('/trustees', [TempleController::class, 'trustees'])->name('trustees');
Route::get('/status-maker', [TempleController::class, 'statusMaker'])->name('status-maker');
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

// Blog
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

// Contact
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'submit'])->name('contact.submit');

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

// Auth
Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
Route::post('/login/otp/send', [AuthWebController::class, 'sendOtp'])->name('login.otp.send');
Route::post('/login/otp/verify', [AuthWebController::class, 'verifyOtp'])->name('login.otp.verify');
Route::post('/logout', [AuthWebController::class, 'logout'])->name('logout');

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
        Route::post('/donate', [DonationWebController::class, 'create'])->name('donate.create');
        Route::post('/seva/{seva}/book', [SevaWebController::class, 'book'])->name('seva.book');
        Route::post('/hall-booking/book', [HallBookingController::class, 'book'])->name('hall.booking.book');

        // Store (authenticated)
        Route::get('/store/cart', [StoreWebController::class, 'cart'])->name('store.cart');
        Route::post('/store/cart/add', [StoreWebController::class, 'addToCart'])->name('store.cart.add');
        // POST instead of PATCH/DELETE — some CDN/WAF rules silently drop
        // non-standard verbs from XHR, leaving the cart session out of sync
        // with the on-page Alpine state.
        Route::post('/store/cart/update', [StoreWebController::class, 'updateCart'])->name('store.cart.update');
        Route::post('/store/cart/remove', [StoreWebController::class, 'removeFromCart'])->name('store.cart.remove');
        Route::post('/store/checkout', [StoreWebController::class, 'checkout'])->name('store.checkout');
        Route::get('/store/order/{order}/invoice', [StoreWebController::class, 'downloadInvoice'])->name('store.order.invoice');
        Route::get('/hall-booking/{booking}/invoice', [HallBookingController::class, 'downloadInvoice'])->name('hall.booking.invoice');

        // Dashboard
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('index');
            Route::get('/donations', [DashboardController::class, 'donations'])->name('donations');
            Route::get('/bookings', [DashboardController::class, 'bookings'])->name('bookings');
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

// Admin-only one-shot storage repair: run from a browser when SSH isn't an
// option. Hostinger disables exec(), so artisan storage:link fails.
// We bypass it by calling PHP's symlink() directly.
Route::get('/admin-tools/storage-repair', function () {
    if (! auth('admin')->check()) {
        abort(403, 'Admin login required.');
    }

    $log = [];
    $log[] = '<pre style="font-family:monospace;padding:16px;background:#111;color:#0f0;border-radius:8px;white-space:pre-wrap">';
    $log[] = '=== Storage Repair ===';

    $linkPath = public_path('storage');
    $target = storage_path('app/public');

    // Ensure storage/app/public exists.
    if (! is_dir($target)) {
        @mkdir($target, 0775, true);
        $log[] = "[ok] created target directory: {$target}";
    }

    // Pre-create the upload directories so the symlink resolves to real folders.
    $publicDirs = [
        'announcements', 'blog', 'campaigns', 'daily-darshan', 'daily-darshan-photos',
        'donation-extras', 'events', 'gallery', 'greeting-templates', 'halls',
        'pages', 'product-categories', 'product-images', 'products',
        'profile-photos', 'sevas',
    ];
    foreach ($publicDirs as $d) {
        $p = $target . '/' . $d;
        if (! is_dir($p)) {
            @mkdir($p, 0775, true);
        }
    }

    // Clean up any existing entry at the link path so we can recreate it.
    if (is_link($linkPath)) {
        $existing = readlink($linkPath);
        if ($existing === $target && is_dir($existing)) {
            $log[] = "[ok] symlink already correct → {$existing}";
        } else {
            @unlink($linkPath);
            $log[] = "[ok] removed stale symlink (was → {$existing})";
        }
    } elseif (is_dir($linkPath) && ! is_link($linkPath)) {
        $log[] = "[!] {$linkPath} is a real directory, not a symlink — leaving it alone";
        $log[] = "    Move its contents into storage/app/public manually, then delete it.";
    } elseif (file_exists($linkPath)) {
        @unlink($linkPath);
        $log[] = "[ok] removed stray file at {$linkPath}";
    }

    // Create the symlink directly (no exec, no artisan).
    if (! is_link($linkPath) && ! is_dir($linkPath)) {
        try {
            $ok = @symlink($target, $linkPath);
            if ($ok) {
                $log[] = "[ok] symlink({$target}, {$linkPath}) succeeded";
            } else {
                $err = error_get_last();
                $log[] = '[err] symlink() returned false: ' . ($err['message'] ?? 'unknown');
            }
        } catch (\Throwable $e) {
            $log[] = '[err] symlink() threw: ' . $e->getMessage();
        }
    }

    // (Removed in Phase 5: the storage:migrate-uploads-to-public call.
    // Uploads no longer live on the local disk, so the legacy migration
    // step is a no-op.)

    $log[] = '';
    $log[] = '=== Quick checks ===';
    $log[] = 'public/storage is_link:        ' . (is_link($linkPath) ? 'yes → ' . readlink($linkPath) : 'no');
    $log[] = 'public/storage exists:         ' . (file_exists($linkPath) ? 'yes' : 'no');
    $log[] = 'storage/app/public/products:   ' . (is_dir(storage_path('app/public/products')) ? 'yes' : 'no');
    $log[] = 'storage/app/private/products:  ' . (is_dir(storage_path('app/private/products')) ? 'yes' : 'no');
    $log[] = 'symlink() function disabled:   ' . (function_exists('symlink') ? 'no' : 'YES — link cannot be created');
    $log[] = '';
    $log[] = 'After this completes, re-upload any product/seva image once from admin.';
    $log[] = 'Direct test: <a style="color:#0ff" href="/storage/products/" target="_blank">/storage/products/</a>';
    $log[] = '</pre>';

    return implode("\n", $log);
})->name('admin.storage-repair');

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
