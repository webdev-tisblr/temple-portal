<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendQueuedNotification;
use App\Models\NotificationOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-enqueue stranded notification outbox rows (Phase H outbox relay).
 *
 * The happy path never needs this: dispatch() writes the row, commit
 * fires, the row is claimed and its job enqueued, the job sends and
 * deletes the row — all within seconds. This command exists for the
 * unhappy paths:
 *
 *   • claimed_at NULL and old  — the process died between the caller's
 *     commit and the enqueue, or Redis was down and the fallback save
 *     also failed. The intent is committed but nobody queued it.
 *   • claimed_at stale         — the job was enqueued but vanished
 *     (worker OOM-killed mid-run, Redis flushed, tries burned out on an
 *     infrastructure error). Claim again and re-enqueue.
 *
 * At-least-once by design: a re-enqueued row racing a live job is safe —
 * the job no-ops when the row is already deleted, and the in-service
 * idempotency window dedups at the per-recipient level below that.
 */
class RelayNotificationOutbox extends Command
{
    protected $signature = 'notifications:relay-outbox
        {--fresh-minutes=5 : Unclaimed rows older than this are relayed}
        {--stale-minutes=15 : Claimed rows untouched for this long are re-relayed}
        {--limit=100 : Max rows per run}';

    protected $description = 'Re-enqueue notification outbox rows the happy path lost (crash between commit and enqueue, dead jobs)';

    public function handle(): int
    {
        $fresh = now()->subMinutes(max(1, (int) $this->option('fresh-minutes')));
        $stale = now()->subMinutes(max(2, (int) $this->option('stale-minutes')));

        $rows = NotificationOutbox::query()
            ->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNull('claimed_at')->where('created_at', '<', $fresh))
                ->orWhere('claimed_at', '<', $stale))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Outbox clear — nothing stranded.');

            return self::SUCCESS;
        }

        $relayed = 0;
        foreach ($rows as $row) {
            try {
                $row->forceFill(['claimed_at' => now()])->save();
                SendQueuedNotification::dispatch(
                    $row->key, [], $row->idempotency_key, $row->only_channels, $row->id,
                )->onQueue($row->queue);
                $relayed++;
            } catch (\Throwable $e) {
                Log::warning('notifications:relay-outbox: enqueue failed', [
                    'outbox_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Relayed {$relayed} of {$rows->count()} stranded outbox row(s).");
        Log::info('notifications:relay-outbox ran', ['found' => $rows->count(), 'relayed' => $relayed]);

        return self::SUCCESS;
    }
}
