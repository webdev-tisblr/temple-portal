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

// Prune expired OTP codes daily
Schedule::command('model:prune', ['--model' => [OtpCode::class]])
    ->daily();

// Database backup at 02:00 every night
Schedule::command('backup:run --only-db')
    ->dailyAt('02:00');

// Regenerate sitemap weekly (Sunday midnight)
Schedule::command('sitemap:generate')
    ->weekly();

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
