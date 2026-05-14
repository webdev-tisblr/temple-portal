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
// R2 lifecycle: cached generated artefacts (receipts, invoices,
// greeting cards, darshan share cards) are reproducible from DB
// rows + service code, so we sweep them on a retention window
// rather than storing forever. Download controllers all call
// `Storage::exists()` + regenerate-if-missing, so deletion is
// transparent to the devotee (1–2 sec one-time render cost on
// re-download). User uploads (profile photos, donation extras,
// admin-curated Filament images) are NEVER touched by these
// sweeps — only DB-reproducible artefacts.
//
// Times are staggered across the small hours (Asia/Kolkata server
// time) so we don't burst R2 API requests in a single minute.
// ────────────────────────────────────────────────────────────────

// Daily Darshan personalised share cards: 30-day retention.
Schedule::command('darshan:clean-share-cards')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// 80G receipt PDFs: 7-day retention. Regenerated via
// ReceiptService::generateReceipt() on next download.
Schedule::command('receipts:clean-generated')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Store + Hall invoice PDFs: 15-day retention. Regenerated via
// InvoiceService::generateInvoice() / GenerateHallInvoice job
// (run synchronously with $sendNotification=false) on next download.
Schedule::command('invoices:clean-generated')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Donation greeting card PNGs: 3-day retention. Most cards are
// shared within hours of donation; longer-tail re-shares trigger
// transparent regenerate via GreetingCardService::generate().
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
