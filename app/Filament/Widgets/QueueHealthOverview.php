<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Jobs\QueueWorkerHeartbeat;
use App\Models\NotificationOutbox;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Queue plumbing at a glance — the "is the engine room OK" row.
 *
 * Built instead of Horizon: at this project's volumes a full queue
 * dashboard is dead weight, but on a spike day (launch, a festival
 * broadcast) the trust needs to see backlog, failures, and worker
 * liveness without SSH. Reads are cheap (three Redis O(1) calls, two
 * small COUNTs, two cache gets) and the widget only renders for roles
 * holding widget_QueueHealthOverview (super admin by default).
 *
 * Liveness stamps:
 *   • scheduler_last_run       — written by the scheduler itself
 *   • queue.worker_heartbeat   — written by a WORKER processing the
 *     probe job, proving scheduler → Redis → worker end to end
 */
class QueueHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    /** Live-ish on spike days without hammering the server. */
    protected static ?string $pollingInterval = '30s';

    protected function getHeading(): ?string
    {
        return 'Queue health';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_QueueHealthOverview') ?? false;
    }

    protected function getStats(): array
    {
        return [
            $this->queueDepthStat('otp', 'OTP queue', 'Login codes — should be 0'),
            $this->queueDepthStat('default', 'Notification queue', 'Confirmations, receipts, reminders'),
            $this->workerStat(),
            $this->failedJobsStat(),
            $this->outboxStat(),
            $this->schedulerStat(),
        ];
    }

    private function queueDepthStat(string $queue, string $label, string $description): Stat
    {
        try {
            // Laravel's redis queue keeps a list (ready), plus delayed and
            // reserved zsets. All three are "not done yet".
            $depth = (int) Redis::llen("queues:{$queue}")
                + (int) Redis::zcard("queues:{$queue}:delayed")
                + (int) Redis::zcard("queues:{$queue}:reserved");
        } catch (\Throwable) {
            return Stat::make($label, '—')
                ->description('Redis unreachable')
                ->color('danger');
        }

        return Stat::make($label, (string) $depth)
            ->description($description)
            ->color($depth === 0 ? 'success' : ($depth < 50 ? 'warning' : 'danger'));
    }

    private function workerStat(): Stat
    {
        return $this->heartbeatStat(
            label: 'Workers',
            iso: Cache::get(QueueWorkerHeartbeat::CACHE_KEY),
            // Probe runs every 5 min; one missed beat is amber, two are red.
            warnAfterMinutes: 7,
            failAfterMinutes: 12,
            neverText: 'No heartbeat yet — workers may never have run',
        );
    }

    private function schedulerStat(): Stat
    {
        return $this->heartbeatStat(
            label: 'Scheduler',
            iso: Cache::get('scheduler_last_run'),
            warnAfterMinutes: 7,
            failAfterMinutes: 12,
            neverText: 'No heartbeat — is cron running?',
        );
    }

    private function heartbeatStat(string $label, mixed $iso, int $warnAfterMinutes, int $failAfterMinutes, string $neverText): Stat
    {
        if (! is_string($iso) || $iso === '') {
            return Stat::make($label, 'silent')->description($neverText)->color('danger');
        }

        try {
            $last = Carbon::parse($iso);
        } catch (\Throwable) {
            return Stat::make($label, 'silent')->description($neverText)->color('danger');
        }

        $minutes = (int) $last->diffInMinutes(now());

        return Stat::make($label, $minutes < 1 ? 'alive' : "alive {$minutes}m ago")
            ->description('Last heartbeat '.$last->diffForHumans())
            ->color($minutes >= $failAfterMinutes ? 'danger' : ($minutes >= $warnAfterMinutes ? 'warning' : 'success'));
    }

    private function failedJobsStat(): Stat
    {
        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return Stat::make('Failed jobs', '—')->color('danger');
        }

        return Stat::make('Failed jobs', (string) $failed)
            ->description($failed === 0 ? 'Nothing dead-lettered' : 'php artisan queue:retry all')
            ->color($failed === 0 ? 'success' : 'danger');
    }

    private function outboxStat(): Stat
    {
        try {
            $total = NotificationOutbox::count();
            $stranded = NotificationOutbox::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q2) => $q2->whereNull('claimed_at')->where('created_at', '<', now()->subMinutes(5)))
                    ->orWhere('claimed_at', '<', now()->subMinutes(15)))
                ->count();
        } catch (\Throwable) {
            return Stat::make('Outbox', '—')->color('danger');
        }

        // In-flight rows (fresh, seconds old) are normal; stranded ones mean
        // the relay has work — and sustained stranding means workers are down.
        return Stat::make('Outbox', (string) $total)
            ->description($stranded > 0 ? "{$stranded} stranded — relay will retry" : 'Intents clear within seconds')
            ->color($stranded > 0 ? 'warning' : 'success');
    }
}
