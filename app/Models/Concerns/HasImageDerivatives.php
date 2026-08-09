<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Jobs\GenerateImageDerivatives;
use App\Services\ImageDerivativeService;
use Illuminate\Support\Facades\Log;

/**
 * Gives a model automatic `thumbnail` / `medium` renditions of its stored
 * image keys. Companion to HasManagedImages (which owns cascade-DELETE);
 * this one owns cascade-CREATE.
 *
 * Implementing models declare:
 *
 *     protected function imageDerivatives(): array
 *     {
 *         return ['image_path' => ['thumbnail' => 'thumbnail_path', 'medium' => 'medium_path']];
 *     }
 *
 * and MUST also list those derivative columns in managedImages() so a
 * deleted record takes its renditions with it instead of orphaning two
 * objects on R2.
 *
 * Generation is queued (redis + 2 Supervisor workers in prod), so an admin
 * upload returns immediately and the thumbnails appear a second later.
 * `images:backfill-derivatives` is the safety net for anything the queue
 * dropped, plus every row that predates this trait.
 */
trait HasImageDerivatives
{
    /**
     * Map of {source column => [variant => derivative column]}.
     * Variants must be keys of ImageDerivativeService::VARIANTS.
     */
    abstract protected function imageDerivatives(): array;

    public static function bootHasImageDerivatives(): void
    {
        // created / updated rather than saved: `wasRecentlyCreated` stays
        // true for the lifetime of the in-memory instance, so a saved()
        // hook keyed on it re-queues on every later save of the same
        // object. Splitting the two makes the trigger exact.
        static::created(function ($model): void {
            foreach ($model->imageDerivatives() as $source => $columns) {
                if (! empty($model->{$source})) {
                    GenerateImageDerivatives::dispatch($model::class, $model->getKey())->afterCommit();

                    return;
                }
            }
        });

        // Only a genuinely NEW source image re-queues. Deliberately not
        // "derivatives are null": the job writes those columns back
        // through save(), which must never re-arm this hook.
        static::updated(function ($model): void {
            foreach ($model->imageDerivatives() as $source => $columns) {
                if (! empty($model->{$source}) && $model->wasChanged($source)) {
                    GenerateImageDerivatives::dispatch($model::class, $model->getKey())->afterCommit();

                    return;
                }
            }
        });
    }

    /** Disk holding both the originals and their renditions. */
    public function derivativeDisk(): string
    {
        return 'r2';
    }

    /**
     * True when every rendition column for every source is populated —
     * i.e. this row needs no work. The backfill's idempotency check.
     */
    public function hasAllImageDerivatives(): bool
    {
        foreach ($this->imageDerivatives() as $source => $columns) {
            if (empty($this->{$source})) {
                continue;
            }

            foreach ($columns as $column) {
                if (empty($this->{$column})) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Generate the missing renditions and persist their keys.
     *
     * @param  bool  $force  regenerate even when the columns are populated
     * @return bool whether any rendition was (re)generated
     */
    public function generateImageDerivatives(bool $force = false): bool
    {
        $service = app(ImageDerivativeService::class);
        $updates = [];

        foreach ($this->imageDerivatives() as $source => $columns) {
            $sourceKey = $this->{$source};

            if (empty($sourceKey)) {
                continue;
            }

            if (! $force) {
                $missing = false;
                foreach ($columns as $column) {
                    if (empty($this->{$column})) {
                        $missing = true;
                    }
                }
                if (! $missing) {
                    continue;
                }
            }

            $generated = $service->generate($sourceKey, $this->derivativeDisk());

            foreach ($columns as $variant => $column) {
                if (! empty($generated[$variant])) {
                    $updates[$column] = $generated[$variant];
                }
            }
        }

        if ($updates === []) {
            return false;
        }

        // Keys are deterministic, so on a --force re-run these values are
        // identical to what is already stored: save() becomes a no-op and
        // HasManagedImages never sees a dirty column, so it cannot delete
        // the object we just rewrote.
        $this->forceFill($updates)->save();

        return true;
    }

    /**
     * generateImageDerivatives() that reports instead of throwing. Used by
     * the queued job and the backfill, where one unreadable original must
     * not abort the batch.
     */
    public function tryGenerateImageDerivatives(bool $force = false): ?string
    {
        try {
            $this->generateImageDerivatives($force);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Image derivative generation failed', [
                'model' => static::class,
                'id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $e->getMessage();
        }
    }
}
