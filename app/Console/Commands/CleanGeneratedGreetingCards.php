<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Donation;
use App\Models\SevaBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sweep cached donation greeting card PNGs from R2 private storage.
 *
 * Greeting cards are deterministic and reproducible from:
 *   • The donation row (devotee name, amount, date, extra_data)
 *   • The donation_type's greeting_card_template + greeting_card_config
 *
 * Most cards are shared on WhatsApp / social within an hour or two of
 * the donation completing. 1-day retention catches that natural usage
 * window without holding files for one-time shares. Long-tail re-views
 * trigger an automatic ~500ms GD regenerate via
 * DonationWebController::greetingCard() — devotee sees no broken link.
 */
class CleanGeneratedGreetingCards extends Command
{
    protected $signature = 'greeting-cards:clean-generated {--days=1 : Cards older than this are deleted (regenerated on next view)}';

    protected $description = 'Delete cached donation greeting card PNGs older than retention — regenerated on demand from DB';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');
            return self::FAILURE;
        }

        $disk = Storage::disk('r2_private');
        $cutoff = now()->subDays($days)->timestamp;
        $deleted = 0;
        $clearedColumns = 0;

        $this->info("Sweeping greeting card PNGs older than {$days} days…");

        foreach ($disk->allFiles('greeting-cards') as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;

                    // Null the path so the next share-link visit triggers
                    // a clean regenerate via the controller's miss-path,
                    // rather than a stale-path check that would 404 if
                    // the DB say "we have a card" but R2 disagrees.
                    // Seva cards live under greeting-cards/seva/ and are
                    // swept by this same walk.
                    $clearedColumns += Donation::where('greeting_card_path', $path)
                        ->update(['greeting_card_path' => null]);
                    $clearedColumns += SevaBooking::where('greeting_card_path', $path)
                        ->update(['greeting_card_path' => null]);
                }
            } catch (\Throwable $e) {
                Log::warning('CleanGeneratedGreetingCards: sweep failed for path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Deleted {$deleted} cached greeting card(s); cleared {$clearedColumns} greeting_card_path columns.");
        Log::info('CleanGeneratedGreetingCards ran', [
            'days' => $days,
            'deleted' => $deleted,
            'columns_cleared' => $clearedColumns,
        ]);

        return self::SUCCESS;
    }
}
