<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries one NotificationService::dispatch() call onto the queue so the
 * driver work (BSP HTTP call, SMTP, FCM) happens in a worker instead of
 * the web request. Enqueued by NotificationService::dispatch() when
 * notifications.via_queue is on — see the gating rules there (no
 * _attachments, snapshot must not truncate; everything else falls back
 * to the legacy inline path).
 *
 * Context travels as a snapshot (models → {class, id} refs) and is
 * rehydrated in the worker via rehydrateSnapshot(), the same round-trip
 * the reaper's retry path has used in production since June. Rows
 * deleted between enqueue and processing drop out cleanly.
 *
 * Retries: deliver() catches driver failures and records them on the
 * NotificationLog row (the reaper owns re-sends), so a job attempt only
 * "fails" on unexpected infrastructure errors (DB briefly down, OOM).
 * tries/backoff cover exactly that class; the in-service idempotency
 * window makes a re-run of an already-delivered send a logged no-op.
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
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $notifications->dispatchNow(
            $this->key,
            NotificationService::rehydrateSnapshot($this->snapshot),
            $this->idempotencyKey,
            $this->onlyChannels,
        );
    }
}
