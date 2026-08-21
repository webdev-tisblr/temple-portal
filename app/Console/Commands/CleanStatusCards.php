<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sweep generated status cards (status-cards/ on the public R2 bucket)
 * older than the retention window. They're deterministic + regenerated on
 * demand by StatusCardService, so retention is purely a storage-cost knob.
 */
class CleanStatusCards extends Command
{
    protected $signature = 'status-cards:clean {--days=1 : Cards older than this are deleted}';

    protected $description = 'Delete generated status cards older than the retention window';

    public function handle(): int
    {
        $disk = Storage::disk('r2');
        $cutoff = now()->subDays(max(1, (int) $this->option('days')))->timestamp;
        $deleted = 0;

        foreach ($disk->allFiles('status-cards') as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                Log::warning('status-cards:clean failed for path', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Deleted {$deleted} generated status card(s).");
        Log::info('status-cards:clean ran', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
