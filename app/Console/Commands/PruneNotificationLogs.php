<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keep temple_notification_logs healthy.
 *
 * One row is written per (template × recipient × attempt), each carrying a
 * context_snapshot of up to ~8 KB. Left unbounded the table slowly grows
 * forever and eventually drags the very queries the safety net depends on
 * (idempotency dedup, the reaper sweep, the admin log view).
 *
 * Retention is deliberately generous — these rows are the audit trail for
 * "did the donor get their receipt?", so we err on the side of keeping
 * them. Successful / skipped rows age out at --days (default 90); failed
 * and any lingering pending rows are kept longer (--failed-days, default
 * 180) so a slow-burn delivery problem stays visible for a couple of
 * quarters. The nightly DB backup is the real archive.
 *
 * Deletes in chunks so a large first run never holds a long table lock.
 */
class PruneNotificationLogs extends Command
{
    protected $signature = 'notifications:prune-logs
        {--days=90 : Delete sent / skipped rows older than this many days}
        {--failed-days=180 : Delete failed / pending rows older than this many days}
        {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Prune old notification log rows on a generous retention policy';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $failedDays = max($days, (int) $this->option('failed-days'));
        $chunk = max(100, (int) $this->option('chunk'));

        $resolvedDeleted = $this->pruneBatch(
            statuses: [NotificationLog::STATUS_SENT, NotificationLog::STATUS_SKIPPED],
            cutoff: now()->subDays($days),
            chunk: $chunk,
        );

        $unresolvedDeleted = $this->pruneBatch(
            statuses: [NotificationLog::STATUS_FAILED, NotificationLog::STATUS_PENDING],
            cutoff: now()->subDays($failedDays),
            chunk: $chunk,
        );

        $total = $resolvedDeleted + $unresolvedDeleted;
        if ($total === 0) {
            $this->info('No notification logs old enough to prune.');
            return self::SUCCESS;
        }

        $this->info("Pruned {$total} notification log row(s) ({$resolvedDeleted} resolved, {$unresolvedDeleted} failed/pending).");
        Log::info('notifications:prune-logs ran', [
            'resolved_deleted' => $resolvedDeleted,
            'unresolved_deleted' => $unresolvedDeleted,
            'days' => $days,
            'failed_days' => $failedDays,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function pruneBatch(array $statuses, \DateTimeInterface $cutoff, int $chunk): int
    {
        $deleted = 0;
        do {
            $batch = NotificationLog::query()
                ->whereIn('status', $statuses)
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
