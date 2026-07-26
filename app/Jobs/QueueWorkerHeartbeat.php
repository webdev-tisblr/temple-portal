<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Liveness probe for the queue workers, scheduled every 5 minutes.
 *
 * The cache stamp is written by the WORKER when it processes this job,
 * so a fresh timestamp proves the entire chain — scheduler → Redis →
 * Supervisor worker — end to end. The admin QueueHealthOverview widget
 * reads it; `supervisorctl status` can say RUNNING while a worker is
 * wedged, which is exactly the lie this probe catches.
 */
final class QueueWorkerHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CACHE_KEY = 'queue.worker_heartbeat';

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), 3600);
    }
}
