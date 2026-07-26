<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationOutbox;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries one NotificationService::dispatch() call onto the queue so the
 * driver work (BSP HTTP call, SMTP, FCM) happens in a worker instead of
 * the web request.
 *
 * Outbox mode ($outboxId set — the only mode dispatch() produces now):
 * the payload lives in the temple_notification_outbox row that was
 * committed atomically with the caller's transaction. The job loads the
 * row, sends, then deletes it. A missing row means another job already
 * processed it (happy-path enqueue racing the relay) — clean no-op, so
 * at-least-once enqueueing never becomes double delivery. On top of
 * that, the in-service idempotency window dedups at the per-recipient
 * level.
 *
 * Legacy mode (snapshot in the payload): kept so jobs enqueued by the
 * previous build that are still on Redis during a deploy keep working.
 *
 * Retries: deliver() catches driver failures and records them on the
 * NotificationLog row (the reaper owns re-sends), so a job attempt only
 * fails on unexpected infrastructure errors (DB briefly down, OOM).
 * If all tries burn out, the outbox row survives — the relay re-enqueues
 * it after the claim goes stale.
 */
final class SendQueuedNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>|null  $onlyChannels
     */
    public function __construct(
        public string $key,
        public array $snapshot,
        public ?string $idempotencyKey,
        public ?array $onlyChannels,
        public ?int $outboxId = null,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        if ($this->outboxId !== null) {
            $row = NotificationOutbox::query()->find($this->outboxId);
            if ($row === null) {
                return; // already processed by a racing job
            }

            $notifications->dispatchNow(
                $row->key,
                NotificationService::rehydrateSnapshot($row->context_snapshot ?? []),
                $row->idempotency_key,
                $row->only_channels,
            );

            $row->delete();

            return;
        }

        // Legacy payload shape (pre-outbox build).
        $notifications->dispatchNow(
            $this->key,
            NotificationService::rehydrateSnapshot($this->snapshot),
            $this->idempotencyKey,
            $this->onlyChannels,
        );
    }
}
