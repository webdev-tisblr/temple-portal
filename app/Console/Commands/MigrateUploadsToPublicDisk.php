<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigrateUploadsToPublicDisk extends Command
{
    protected $signature = 'storage:migrate-uploads-to-public {--dry-run : Show what would be moved without moving}';

    protected $description = 'Move legacy admin uploads from storage/app/private/* into storage/app/public/* so the storage symlink can serve them.';

    /**
     * Image-bearing top-level directories we let admins upload into. Anything
     * not listed here (receipts, invoices, hall-invoices, filament_exports,
     * livewire-tmp, greeting-cards) stays private.
     */
    private const PUBLIC_DIRS = [
        'announcements',
        'blog',
        'campaigns',
        'daily-darshan-photos',
        'donation-extras',
        'events',
        'gallery',
        'greeting-templates',
        'halls',
        'pages',
        'product-categories',
        'product-images',
        'products',
        'profile-photos',
        'sevas',
    ];

    public function handle(): int
    {
        $privateRoot = storage_path('app/private');
        $publicRoot = storage_path('app/public');

        if (! is_dir($privateRoot)) {
            $this->info('Nothing to do — storage/app/private does not exist.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $skipped = 0;

        foreach (self::PUBLIC_DIRS as $dir) {
            $from = $privateRoot . '/' . $dir;
            $to = $publicRoot . '/' . $dir;

            if (! is_dir($from)) {
                continue;
            }

            $this->line("→ {$dir}/");

            if (! is_dir($to)) {
                if ($dryRun) {
                    $this->line("    would create {$to}");
                } else {
                    File::ensureDirectoryExists($to, 0775, true);
                }
            }

            $files = File::allFiles($from);
            foreach ($files as $file) {
                $relative = ltrim(str_replace($from, '', $file->getPathname()), '/');
                $dest = $to . '/' . $relative;

                if (file_exists($dest)) {
                    $this->line("    skip (exists in public): {$relative}");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("    would move: {$relative}");
                    $moved++;
                    continue;
                }

                File::ensureDirectoryExists(dirname($dest), 0775, true);
                if (rename($file->getPathname(), $dest)) {
                    $moved++;
                } else {
                    $this->error("    failed to move: {$relative}");
                }
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Moved: {$moved}, Skipped: {$skipped}");
        $this->line('After deploy, ensure storage:link is set up: php artisan storage:link');

        return self::SUCCESS;
    }
}
