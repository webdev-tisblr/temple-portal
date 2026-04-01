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
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\SevaWebController;
use App\Http\Controllers\Web\TempleController;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

// Seva
Route::get('/seva', [SevaWebController::class, 'index'])->name('seva.index');
Route::get('/seva/{seva}', [SevaWebController::class, 'show'])->name('seva.show');

// Donation (public view)
Route::get('/donate', [DonationWebController::class, 'index'])->name('donate');
Route::get('/donate/thank-you', [DonationWebController::class, 'thankYou'])->name('donate.thanks');

// Temple info
Route::get('/darshan', [TempleController::class, 'darshan'])->name('darshan');
Route::get('/trustees', [TempleController::class, 'trustees'])->name('trustees');
Route::get('/pujari', [TempleController::class, 'pujari'])->name('pujari');
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

// Auth
Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
Route::post('/login/otp/send', [AuthWebController::class, 'sendOtp'])->name('login.otp.send');
Route::post('/login/otp/verify', [AuthWebController::class, 'verifyOtp'])->name('login.otp.verify');
Route::post('/logout', [AuthWebController::class, 'logout'])->name('logout');

// Authenticated devotee routes
Route::middleware('auth:devotee')->group(function () {
    Route::post('/donate', [DonationWebController::class, 'create'])->name('donate.create');
    Route::post('/seva/{seva}/book', [SevaWebController::class, 'book'])->name('seva.book');
    Route::get('/seva/booking/success', [SevaWebController::class, 'bookingSuccess'])->name('seva.booking.success');
    Route::get('/seva/booking/failure', [SevaWebController::class, 'bookingFailure'])->name('seva.booking.failure');

    // Dashboard
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::get('/donations', [DashboardController::class, 'donations'])->name('donations');
        Route::get('/bookings', [DashboardController::class, 'bookings'])->name('bookings');
        Route::get('/receipts', [DashboardController::class, 'receipts'])->name('receipts');
        Route::get('/receipts/{receipt}/download', [DashboardController::class, 'downloadReceipt'])->name('receipts.download');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
    });
});

// CMS Pages (catch-all — MUST BE LAST)
Route::get('/{slug}', [PageController::class, 'show'])->name('page.show');
