<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The notification safety net.
 *
 * NotificationService sends inline (the queue connection is `sync`), so a
 * send can be lost in two ways the rest of the system never recovers from:
 *
 *   1. STALLED — the PHP worker died mid-send (timeout / OOM / restart)
 *      after the `pending` log row was written but before it was updated
 *      to sent/failed. The row sits `pending` forever, the recipient never
 *      got the message, and nothing retries it.
 *
 *   2. FAILED — the driver returned false / threw (transient SMTP refusal,
 *      WhatsApp BSP hiccup, FCM blip). Logged as `failed`, then forgotten
 *      unless an admin happens to notice and clicks Resend.
 *
 * This command sweeps both classes on a short cadence and re-attempts them
 * in place via NotificationService::retryLog(), which bumps the row's
 * `attempts` column. `attempts` is therefore a self-contained retry budget:
 * once a row reaches --max-attempts it is left alone (still visible as
 * `failed` for an admin to inspect).
 *
 * Safety rails (so a first run on an existing prod table can't flood):
 *   • --lookback-hours bounds how far back we look (default 6h). Ancient
 *     failures are never resurrected.
 *   • --limit caps rows processed per run (default 200).
 *   • --stalled-minutes guards the pending case: only rows older than this
 *     are treated as stalled, because a *successful* send updates its row
 *     within the same request/tick (seconds). A long threshold makes it
 *     near-certain the original send never completed, minimising the small
 *     at-least-once risk of a duplicate send.
 *   • Time-sensitive keys (OTP) are skipped — a code re-sent minutes later
 *     has expired and only confuses the recipient. Reliability there is a
 *     send-path concern, not a reaper concern.
 *   • A `failed` row is skipped if a later attempt for the same
 *     idempotency_key + channel already succeeded.
 *
 * Scheduled every few minutes via routes/console.php with
 * withoutOverlapping().
 */
class ReapNotifications extends Command
{
    protected $signature = 'notifications:reap
        {--lookback-hours=6 : Only consider log rows created within this many hours}
        {--stalled-minutes=15 : A pending row older than this is treated as a stalled (lost) send}
        {--max-attempts=3 : Stop retrying a row once it has been attempted this many times}
        {--limit=200 : Maximum rows to re-attempt in a single run}
        {--dry-run : Report what would be retried without sending anything}';

    protected $description = 'Retry notification sends that stalled mid-flight or failed transiently (the notification safety net)';

    /**
     * Trigger keys whose messages lose all value if delivered late, so
     * auto-retry would do more harm (confusion) than good.
     */
    private const SKIP_KEYS = ['auth.otp'];

    public function handle(NotificationService $notifications): int
    {
        $lookbackHours = max(1, (int) $this->option('lookback-hours'));
        $stalledMinutes = max(1, (int) $this->option('stalled-minutes'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $lookbackCutoff = now()->subHours($lookbackHours);
        $stalledCutoff = now()->subMinutes($stalledMinutes);

        $candidates = NotificationLog::query()
            ->where('created_at', '>=', $lookbackCutoff)
            ->where('attempts', '<', $maxAttempts)
            ->whereNotIn('template_key', self::SKIP_KEYS)
            ->where(function ($q) use ($stalledCutoff) {
                // Stalled: written pending, never resolved, send window elapsed.
                $q->where(function ($q2) use ($stalledCutoff) {
                    $q2->where('status', NotificationLog::STATUS_PENDING)
                        ->where('created_at', '<=', $stalledCutoff);
                })
                // Transiently failed.
                ->orWhere('status', NotificationLog::STATUS_FAILED);
            })
            // Oldest first so the longest-waiting recipients are served first.
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to reap.');
            return self::SUCCESS;
        }

        $retried = 0;
        $recovered = 0;
        $skipped = 0;

        foreach ($candidates as $log) {
            // Don't re-send if a later attempt for the same logical message
            // already went through (the idempotency key ties retries to one
            // underlying resource, e.g. payment:{id}:donation.confirmed:...).
            if ($this->alreadySucceededLater($log)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] would retry #%s  %s  %s  status=%s attempts=%d',
                    $log->getKey(), $log->template_key, $log->channel, $log->status, $log->attempts,
                ));
                $retried++;
                continue;
            }

            $ok = $notifications->retryLog($log);
            $retried++;
            if ($ok) {
                $recovered++;
            }
        }

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: %d notification(s) would be retried, %d skipped (superseded).',
                $retried, $skipped,
            ));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Reaped %d notification(s): %d recovered, %d still failing, %d skipped (superseded).',
            $retried, $recovered, $retried - $recovered, $skipped,
        ));

        if (! $dryRun && $retried > 0) {
            Log::info('notifications:reap ran', [
                'considered' => $candidates->count(),
                'retried' => $retried,
                'recovered' => $recovered,
                'skipped_superseded' => $skipped,
                'lookback_hours' => $lookbackHours,
                'stalled_minutes' => $stalledMinutes,
                'max_attempts' => $maxAttempts,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * True if some other attempt for this row's idempotency_key + channel
     * has already reached `sent`. Prevents re-sending a message a later
     * attempt (admin Resend, webhook+verify race, prior reaper pass)
     * already delivered. Rows with no idempotency key can't be correlated
     * this way, so they fall through to a normal retry.
     */
    private function alreadySucceededLater(NotificationLog $log): bool
    {
        if (empty($log->idempotency_key)) {
            return false;
        }

        return NotificationLog::query()
            ->where('idempotency_key', $log->idempotency_key)
            ->where('channel', $log->channel)
            ->where('status', NotificationLog::STATUS_SENT)
            ->where('id', '!=', $log->getKey())
            ->exists();
    }
}
