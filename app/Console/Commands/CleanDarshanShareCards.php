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
 * Default retention is 1 day. A new darshan photo is uploaded by the
 * admin every morning, which makes yesterday's cards functionally
 * dead — the URL is hash-bound to the photo's updated_at, so old
 * cards are never re-served when the share button regenerates a
 * URL for today's photo. Anyone visiting a stale share URL from
 * yesterday's WhatsApp message gets the CDN-cached copy until the
 * 30-day edge cache expires; after that, the share link 404s, which
 * is the desired outcome for outdated darshan photos.
 *
 * Pass --days=N to override.
 */
class CleanDarshanShareCards extends Command
{
    protected $signature = 'darshan:clean-share-cards {--days=1 : Cards older than this are deleted}';

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
