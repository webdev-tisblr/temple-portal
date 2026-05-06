<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Cascade-delete trait for models whose columns hold storage keys.
 *
 * Implementing models declare a `managedImages()` method returning a map of
 * `column => disk`. The trait then automatically:
 *
 *   - Deletes the R2 object when the parent record is removed (real delete,
 *     not soft delete — soft-deleted records can still be restored).
 *   - Deletes the OLD R2 object when the column value changes, so updates
 *     (web-side profile-photo replacement, admin file swap, etc.) don't
 *     leave orphaned objects in the bucket.
 *
 * Example:
 *
 *     class Seva extends Model
 *     {
 *         use HasManagedImages;
 *
 *         protected function managedImages(): array
 *         {
 *             return ['image_path' => 'r2'];
 *         }
 *     }
 */
trait HasManagedImages
{
    /**
     * Map of {column name => Filesystem disk name}.
     * Implementing models override this.
     */
    abstract protected function managedImages(): array;

    public static function bootHasManagedImages(): void
    {
        // On record deletion: drop every managed image from its disk.
        // Skip on soft-delete (recoverable); only act on actual row removal.
        static::deleted(function ($model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            foreach ($model->managedImages() as $column => $disk) {
                $path = $model->getOriginal($column) ?: ($model->{$column} ?? null);
                if (! empty($path)) {
                    self::deleteManagedObject($disk, $path, $model);
                }
            }
        });

        // On record update: if any image column was just changed, drop the
        // OLD object before the new value is committed to disk by Eloquent.
        static::updating(function ($model): void {
            foreach ($model->managedImages() as $column => $disk) {
                if ($model->isDirty($column)) {
                    $oldPath = $model->getOriginal($column);
                    if (! empty($oldPath)) {
                        self::deleteManagedObject($disk, $oldPath, $model);
                    }
                }
            }
        });
    }

    /**
     * Delete a single object from a Filesystem disk, swallowing errors so
     * a transient R2 hiccup doesn't roll back the user-facing operation.
     * Worst case we leak one orphaned object — far better than 500'ing
     * the request.
     */
    protected static function deleteManagedObject(string $disk, string $path, $model): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Managed image cascade-delete failed', [
                'disk' => $disk,
                'path' => $path,
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
