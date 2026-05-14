<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DarshanShareCardService;
use Illuminate\Console\Command;

/**
 * Sweep old personalised Daily Darshan share cards from R2. Cards are
 * deterministically named so they'll be regenerated on demand if any
 * devotee ever requests an old one again — retention exists only to
 * keep storage costs bounded.
 *
 * Default retention is 30 days. Pass --days=N to override.
 */
class CleanDarshanShareCards extends Command
{
    protected $signature = 'darshan:clean-share-cards {--days=30 : Cards older than this are deleted}';

    protected $description = 'Delete personalised Daily Darshan share cards older than the retention window';

    public function handle(DarshanShareCardService $service): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');
            return self::FAILURE;
        }

        $this->info("Sweeping Daily Darshan share cards older than {$days} days…");
        $deleted = $service->cleanup($days);
        $this->info("Deleted {$deleted} card(s).");

        return self::SUCCESS;
    }
}
