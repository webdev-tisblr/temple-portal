<?php

declare(strict_types=1);

use App\Jobs\QueueWorkerHeartbeat;
use App\Jobs\SendPushNotification;
use App\Models\DonationCampaign;
use App\Models\Notification;
use App\Models\OtpCode;
use App\Models\WebLoginToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks — শ્રી પાતાળિયા હનુમાનજી Temple Portal
|--------------------------------------------------------------------------
*/

// NOTE: there is deliberately no scheduled `queue:work` here. The two
// always-on Supervisor workers own the queue (see CLAUDE.md). The old
// every-5-minute fallback was a Hostinger shared-hosting artifact; running
// it alongside the workers just spawns a competing short-lived consumer.

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
})->everyMinute()->name('dispatch-scheduled-notifications')->withoutOverlapping(5);

// Cancel stale pending bookings every 5 minutes
Schedule::command('bookings:clean-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Seva reminders — every 5 min. Reminders are pre-computed into
// temple_seva_reminder_schedules when a booking is confirmed (see
// SevaReminderScheduler / SevaBookingObserver); this command simply
// drains the due rows. Because each reminder is a durable pending row,
// a missed tick (deploy, blip) no longer loses it — the next run picks
// it up and sends it a little late instead of never. Within ~5 min of
// the configured offset on a healthy schedule.
// The 10-minute mutex expiry is load-bearing: the drain permanently skips
// anything more than --max-late-minutes (12h) late, so a leaked lock on
// Laravel's 24h default would silently bin half a day of reminders.
// Seva greeting cards go out on the MORNING OF THE SEVA, not when the booking
// was paid for. withoutOverlapping carries an explicit short expiry: Laravel's
// default lock is 24h, which on a daily task could silently skip a whole day.
Schedule::command('seva:send-day-of-cards')
    ->dailyAt('07:30')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('seva:dispatch-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// Hall booking reminders. Same shape and the same 10-minute lock expiry:
// the command permanently skips anything more than 12h late, so a leaked
// lock from Laravel's 24h default would silently bin half a day of them.
// Opens the site when the configured launch moment passes. The middleware
// already lets traffic through at that instant on its own — this is what
// flips the stored flag and purges the edge cache so the world sees it.
// Every minute, because "we launch at 9:00" means 9:00.
Schedule::command('site:launch')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('hall:dispatch-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// Notification safety net — retry sends that stalled mid-flight (worker
// died after writing the `pending` row) or failed transiently (SMTP /
// WhatsApp / FCM blip). Re-attempts in place with a bounded budget
// (the attempts column), so nothing silently vanishes. Every 5 min keeps
// recovery tight without hammering providers; withoutOverlapping() means
// a slow run never stacks on the next tick. See ReapNotifications.
Schedule::command('notifications:reap')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function () {
        Log::error('Scheduled task failed: notifications:reap');
    });

// Outbox relay — re-enqueues queue-backed notification intents that the
// happy path lost (process died between commit and enqueue, Redis blip,
// job burned its tries on an infrastructure error). No-ops when the
// via_queue flag is off (the table simply stays empty).
Schedule::command('notifications:relay-outbox')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function () {
        Log::error('Scheduled task failed: notifications:relay-outbox');
    });

// Keep the per-attempt notification audit log from growing forever.
// Generous retention (90d resolved / 180d failed); the nightly DB
// backup is the real archive. Runs in the small hours, staggered off
// the R2 sweeps.
Schedule::command('notifications:prune-logs')
    ->dailyAt('04:30')
    ->withoutOverlapping(30);

// Prune expired OTP codes + spent app→web login handoff tokens daily
Schedule::command('model:prune', ['--model' => [OtpCode::class, WebLoginToken::class]])
    ->daily();

// GC expired Sanctum token rows (90-day expiry; single-active-login also
// hard-deletes on re-login, but tokens from abandoned devices linger).
Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->dailyAt('04:45')
    ->withoutOverlapping(30);

// Database backup at 02:00 every night. A missed/failed backup is a
// silent catastrophe (you only find out you have no backup when you
// need to restore), so log failures loudly for the monitor to catch.
Schedule::command('backup:run --only-db')
    ->dailyAt('02:00')
    ->onFailure(function () {
        Log::error('Scheduled task failed: backup:run --only-db');
    });

// Apply the retention strategy in config/backup.php (7 days full, then
// spatie's thinning, 5GB cap) — without this the bucket grows forever.
Schedule::command('backup:clean')
    ->dailyAt('02:30')
    ->onFailure(function () {
        Log::error('Scheduled task failed: backup:clean');
    });

// Health check: logs loudly when the newest backup is older than a day
// or the bucket is unreachable — a silent backup failure is the worst
// kind. Runs mid-morning so a failed 02:00 run is flagged same day.
Schedule::command('backup:monitor')
    ->dailyAt('10:00')
    ->onFailure(function () {
        Log::error('Scheduled task failed: backup:monitor — CHECK BACKUPS');
    });

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
// Generated status cards are a 30-day regenerable cache on r2 public.
Schedule::command('status-cards:clean')
    ->dailyAt('04:45')
    ->withoutOverlapping(30);

Schedule::command('darshan:clean-share-cards')
    ->hourlyAt(30)
    ->withoutOverlapping(30);

// 80G receipt PDFs: 7-day retention, daily sweep. Regenerated via
// ReceiptService::generateReceipt() on next download (~1s DomPDF).
// Hourly would be overkill at a 7-day window — the storage delta
// between "swept hourly" and "swept daily" is one day's worth of
// PDFs at 100-150 KB each, which is noise.
Schedule::command('receipts:clean-generated')
    ->dailyAt('03:45')
    ->withoutOverlapping(30);

// Store + Hall invoice PDFs: 7-day retention, daily sweep. Same
// reasoning as receipts. Regenerated via InvoiceService::generateInvoice()
// / GenerateHallInvoice job (run synchronously with $sendNotification=false)
// on next download (~1s DomPDF).
Schedule::command('invoices:clean-generated')
    ->dailyAt('04:00')
    ->withoutOverlapping(30);

// Donation greeting card PNGs: 1-day retention, swept HOURLY for
// the same scale reason as darshan cards. Devotees share on
// WhatsApp within minutes-hours of donation. Long-tail re-views
// trigger a transparent ~500ms GD regenerate via GreetingCardService.
// Minute :45 staggers off the receipts sweep at 03:45 (which only
// fires once a day, but no harm having distinct slots).
Schedule::command('greeting-cards:clean-generated')
    ->hourlyAt(45)
    ->withoutOverlapping(30);

// Self-healing pass for image renditions. Generation normally happens on
// the queue the moment an image is uploaded (HasImageDerivatives); this
// only ever finds rows where that job failed or was dropped, which would
// otherwise leave the mobile app decoding a full-resolution original
// forever. The query filters completed rows out in SQL, so on a healthy
// day this is one cheap COUNT and exits. --limit keeps a bad day from
// pinning the 2-vCPU box; the next night picks up where it stopped.
Schedule::command('images:backfill-derivatives --limit=100 --sleep=1')
    ->dailyAt('04:30')
    ->withoutOverlapping(60);

// Update campaign raised_amount and donor_count totals hourly.
//
// Single grouped query instead of the old per-campaign correlated
// subquery (one query + one UPDATE per active campaign → N+1). We join
// captured payments to donations ONCE, GROUP BY campaign, then update
// each active campaign from the result map. Semantics are preserved
// exactly: only payments with status='captured' count, only active
// campaigns are updated, and an active campaign with no captured
// donations is reset to 0 (the map lookup falls back to 0).
Schedule::call(function () {
    $totalsByCampaign = DB::table('temple_donations')
        ->join('temple_payments', 'temple_payments.id', '=', 'temple_donations.payment_id')
        ->where('temple_payments.status', 'captured')
        ->whereNotNull('temple_donations.campaign_id')
        ->groupBy('temple_donations.campaign_id')
        ->selectRaw('temple_donations.campaign_id as campaign_id, SUM(temple_donations.amount) as total_amount, COUNT(DISTINCT temple_donations.devotee_id) as total_donors')
        ->get()
        ->keyBy('campaign_id');

    DonationCampaign::query()
        ->where('is_active', true)
        ->each(function (DonationCampaign $campaign) use ($totalsByCampaign) {
            $totals = $totalsByCampaign->get($campaign->id);

            $campaign->update([
                'raised_amount' => $totals->total_amount ?? 0,
                'donor_count' => $totals->total_donors ?? 0,
            ]);
        });
})->hourly()->name('update-campaign-totals')
    ->onFailure(function () {
        Log::error('Scheduled task failed: update-campaign-totals');
    });

// ────────────────────────────────────────────────────────────────
// Scheduler heartbeat — write a fresh timestamp every 5 minutes. If
// the cron that runs `schedule:run` dies (Hostinger cron disabled, PHP
// broken after a bad deploy, etc.) this key goes stale, which an
// external check / the /up health probe consumer can detect. 600s TTL
// means the key expires ~2 ticks after the last successful run, so a
// dead scheduler is unambiguous rather than a forever-stale value.
// ────────────────────────────────────────────────────────────────
Schedule::call(function () {
    Cache::put('scheduler_last_run', now()->toIso8601String(), 600);
})->everyFiveMinutes()->name('scheduler-heartbeat');

// Worker liveness probe — the stamp is written by the WORKER, not here,
// so it proves scheduler → Redis → Supervisor worker end to end. Read
// by the admin QueueHealthOverview widget. Only meaningful where the
// queue is worker-backed; on sync/database-cron setups the job runs
// inline and the stamp simply mirrors the scheduler heartbeat.
Schedule::call(function () {
    QueueWorkerHeartbeat::dispatch();
})->everyFiveMinutes()->name('queue-worker-heartbeat');
