<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds the thumbnail/medium renditions for one record that uses
 * HasImageDerivatives.
 *
 * Carries the class + key rather than the model itself: the row may have
 * been edited (or deleted) between dispatch and execution, and a stale
 * serialised copy would regenerate from the wrong source key. A vanished
 * row is a no-op, not a failure.
 */
class GenerateImageDerivatives implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        /** @var class-string<Model> */
        public string $modelClass,
        public int|string $modelKey,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        if (! class_exists($this->modelClass)) {
            return;
        }

        /** @var Model|null $model */
        $model = $this->modelClass::query()->find($this->modelKey);

        if ($model === null || ! method_exists($model, 'tryGenerateImageDerivatives')) {
            return;
        }

        // Fail-soft on purpose. This job rides on an admin save, and
        // QUEUE_CONNECTION has been `sync` in production before now — a
        // throw here would 500 the save over a thumbnail. The failure is
        // logged, the row keeps NULL rendition columns, and
        // `images:backfill-derivatives` picks it up on the next pass.
        $model->tryGenerateImageDerivatives($this->force);
    }
}
