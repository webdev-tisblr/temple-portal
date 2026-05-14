<?php

declare(strict_types=1);

use App\Jobs\SendPushNotification;
use App\Models\DonationCampaign;
use App\Models\Notification;
use App\Models\OtpCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks — শ્રી પાતળિયા હનુમાનજી Temple Portal
|--------------------------------------------------------------------------
*/

// Process queued jobs every 5 minutes (stop-on-failure, no overlap)
Schedule::command('queue:work --stop-when-empty --tries=3')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Retry failed jobs hourly
Schedule::command('queue:retry all')
    ->hourly();

// Birthday blessings: dispatched at 07:00 every morning
Schedule::command('temple:send-birthday-blessings')
    ->dailyAt('07:00');

// Dispatch scheduled push notifications every 5 minutes
Schedule::call(function () {
    $due = Notification::query()
        ->where('status', 'pending')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->get();

    foreach ($due as $notification) {
        $notification->update(['status' => 'processing']);
        SendPushNotification::dispatch($notification);
    }
})->everyFiveMinutes()->name('dispatch-scheduled-notifications')->withoutOverlapping();

// Cancel stale pending bookings every 5 minutes
Schedule::command('bookings:clean-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Prune expired OTP codes daily
Schedule::command('model:prune', ['--model' => [OtpCode::class]])
    ->daily();

// Database backup at 02:00 every night
Schedule::command('backup:run --only-db')
    ->dailyAt('02:00');

// Regenerate sitemap weekly (Sunday midnight)
Schedule::command('sitemap:generate')
    ->weekly();

// ────────────────────────────────────────────────────────────────
// R2 lifecycle: every cached file under these sweeps is fully
// reproducible from DB + service code, so we treat r2_private as a
// short-lived cache rather than archival storage. Download
// controllers all call `Storage::exists()` + regenerate-if-missing,
// so deletion is invisible to the devotee — they pay a ~1s
// re-render on the first download after a sweep, then it's cached
// again until the next sweep.
//
// User uploads (profile photos, donation extras, all admin-curated
// Filament images on r2 public bucket) are NEVER touched here —
// they have no DB-only source of truth and must be retained.
//
// Times are staggered across the small hours (Asia/Kolkata server
// time) so we don't burst R2 API requests in a single minute.
// Aggressive retention values are deliberate: regeneration is cheap
// (1-2 seconds), and storage cost is the only reason to hold these
// files at all. Stretching retention provides no UX benefit beyond
// the first day or two.
// ────────────────────────────────────────────────────────────────

// Daily Darshan personalised share cards: 1-day retention. Admin
// uploads a new darshan photo each morning, so yesterday's cards
// are functionally dead. CDN edge-cache (30 days max-age) keeps
// already-shared URLs working past R2 deletion until natural
// expiry; new shares always render fresh.
Schedule::command('darshan:clean-share-cards')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// 80G receipt PDFs: 7-day retention. Regenerated via
// ReceiptService::generateReceipt() on next download (~1s DomPDF).
Schedule::command('receipts:clean-generated')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Store + Hall invoice PDFs: 7-day retention. Regenerated via
// InvoiceService::generateInvoice() / GenerateHallInvoice job
// (run synchronously with $sendNotification=false) on next
// download (~1s DomPDF).
Schedule::command('invoices:clean-generated')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Donation greeting card PNGs: 1-day retention. Devotees share on
// WhatsApp within minutes-hours of donation; long-tail re-views
// trigger transparent regenerate via GreetingCardService (~500ms
// GD), so 24h covers the entire natural usage window.
Schedule::command('greeting-cards:clean-generated')
    ->dailyAt('04:15')
    ->withoutOverlapping();

// Update campaign raised_amount and donor_count totals hourly
Schedule::call(function () {
    DonationCampaign::query()
        ->where('is_active', true)
        ->each(function (DonationCampaign $campaign) {
            $totals = DB::table('temple_donations')
                ->where('campaign_id', $campaign->id)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('temple_payments')
                        ->whereColumn('temple_payments.id', 'temple_donations.payment_id')
                        ->where('temple_payments.status', 'captured');
                })
                ->selectRaw('SUM(amount) as total_amount, COUNT(DISTINCT devotee_id) as total_donors')
                ->first();

            $campaign->update([
                'raised_amount' => $totals->total_amount ?? 0,
                'donor_count' => $totals->total_donors ?? 0,
            ]);
        });
})->hourly()->name('update-campaign-totals');
