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
| Scheduled Tasks — শ્રી પાતાળિયા હનુમાનજી Temple Portal
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

// Dispatch scheduled push notifications every minute. Admin picks a
// minute-precision scheduled_at in the Filament form (no seconds), so
// every-minute polling means the push arrives within ~1 minute of the
// chosen time. The previous 5-minute cadence made the actual send up
// to 5 min late, which surprised admins who expected the time they
// picked to be honoured precisely.
// Status flow: draft → scheduled → sending → sent | failed
Schedule::call(function () {
    $due = Notification::query()
        ->where('status', 'scheduled')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->get();

    foreach ($due as $notification) {
        $notification->update(['status' => 'sending']);
        SendPushNotification::dispatch($notification);
    }
})->everyMinute()->name('dispatch-scheduled-notifications')->withoutOverlapping();

// Cancel stale pending bookings every 5 minutes
Schedule::command('bookings:clean-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Seva reminders — every 5 min. Each Seva resource carries its own
// reminders[] array; this command fans out per-booking × per-reminder
// dispatches to devotee + pujari-role admins. A 5-minute cadence with
// a 10-min window (command default) keeps reminders within ~5 minutes
// of the configured offset. The previous 30-minute cadence drifted
// half an hour late on average, which read as "no reminder arrived"
// for tighter offsets like 3h-before.
Schedule::command('seva:dispatch-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Notification safety net — retry sends that stalled mid-flight (worker
// died after writing the `pending` row) or failed transiently (SMTP /
// WhatsApp / FCM blip). Re-attempts in place with a bounded budget
// (the attempts column), so nothing silently vanishes. Every 5 min keeps
// recovery tight without hammering providers; withoutOverlapping() means
// a slow run never stacks on the next tick. See ReapNotifications.
Schedule::command('notifications:reap')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Keep the per-attempt notification audit log from growing forever.
// Generous retention (90d resolved / 180d failed); the nightly DB
// backup is the real archive. Runs in the small hours, staggered off
// the R2 sweeps.
Schedule::command('notifications:prune-logs')
    ->dailyAt('04:30')
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

// Daily Darshan personalised share cards: 1-day retention, swept
// HOURLY. Admin uploads a new darshan photo each morning, so
// yesterday's cards are functionally dead. With expected scale of
// 5K+ devotees and ~1000+ shares/day, an hourly sweep keeps the
// effective retention tight at 24-25h (vs 24-48h with a daily
// sweep). CDN edge-cache (30 days max-age) keeps already-shared
// URLs working past R2 deletion until natural eviction. Minute :30
// chosen to stagger off the queue:work pulse at *:00 and *:05.
Schedule::command('darshan:clean-share-cards')
    ->hourlyAt(30)
    ->withoutOverlapping();

// 80G receipt PDFs: 7-day retention, daily sweep. Regenerated via
// ReceiptService::generateReceipt() on next download (~1s DomPDF).
// Hourly would be overkill at a 7-day window — the storage delta
// between "swept hourly" and "swept daily" is one day's worth of
// PDFs at 100-150 KB each, which is noise.
Schedule::command('receipts:clean-generated')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Store + Hall invoice PDFs: 7-day retention, daily sweep. Same
// reasoning as receipts. Regenerated via InvoiceService::generateInvoice()
// / GenerateHallInvoice job (run synchronously with $sendNotification=false)
// on next download (~1s DomPDF).
Schedule::command('invoices:clean-generated')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Donation greeting card PNGs: 1-day retention, swept HOURLY for
// the same scale reason as darshan cards. Devotees share on
// WhatsApp within minutes-hours of donation. Long-tail re-views
// trigger a transparent ~500ms GD regenerate via GreetingCardService.
// Minute :45 staggers off the receipts sweep at 03:45 (which only
// fires once a day, but no harm having distinct slots).
Schedule::command('greeting-cards:clean-generated')
    ->hourlyAt(45)
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
