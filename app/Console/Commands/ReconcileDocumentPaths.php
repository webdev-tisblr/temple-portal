<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * NULL the path columns whose R2 object is gone.
 *
 * Every download endpoint self-heals on a NULL path and deliberately does
 * NOT probe R2 first — see DashboardController::downloadReceipt, which says
 * so outright: "No R2 ->exists() probe … the sweep NULLs the path, so
 * non-null == present." That invariant is what makes a regenerable cache
 * work without paying an S3 HEAD on every download.
 *
 * Our own sweepers honour it. Anything else that deletes an object does
 * not, and something does: on 2026-08-21, 24 rows pointed at greeting
 * cards that no longer existed, all of them past the 1-day retention.
 * Cloudflare-side lifecycle rules are the likely culprit, and they know
 * nothing about our database. The result is the one case the download path
 * cannot handle — a non-null path with no file behind it, which self-heals
 * into a broken link instead of a regenerated document.
 *
 * So this restores the invariant from the other end: find rows whose file
 * has vanished, NULL the column, and let the normal regenerate-on-miss
 * path take over the next time a devotee asks for it.
 *
 * Deliberately NOT a delete of anything. It only ever clears a pointer to
 * something that is already gone.
 */
class ReconcileDocumentPaths extends Command
{
    protected $signature = 'documents:reconcile-paths {--dry-run : Report without writing}';

    protected $description = 'NULL document path columns whose R2 object no longer exists, so downloads regenerate';

    /** @var list<array{0:string,1:string,2:string}> table, column, disk */
    private const COLUMNS = [
        ['temple_donations', 'greeting_card_path', 'r2_private'],
        ['temple_seva_bookings', 'greeting_card_path', 'r2_private'],
        ['temple_seva_bookings', 'receipt_path', 'r2_private'],
        ['temple_orders', 'invoice_path', 'r2_private'],
        ['temple_hall_bookings', 'invoice_path', 'r2_private'],
        ['temple_receipts_80g', 'pdf_path', 'r2_private'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cleared = 0;

        foreach (self::COLUMNS as [$table, $column, $disk]) {
            $fs = Storage::disk($disk);

            // One listing per disk-prefix beats an exists() per row: these
            // tables grow, and a HEAD each would turn a maintenance pass
            // into thousands of round trips.
            static $present = [];
            if (! isset($present[$disk])) {
                $present[$disk] = array_flip($fs->allFiles());
            }

            $missing = DB::table($table)
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->pluck($column, 'id')
                ->filter(fn ($path) => ! isset($present[$disk][ltrim((string) $path, '/')]));

            if ($missing->isEmpty()) {
                $this->line(sprintf('  %-42s ok', $table.'.'.$column));

                continue;
            }

            $this->warn(sprintf('  %-42s %d pointing at a deleted file', $table.'.'.$column, $missing->count()));

            if (! $dryRun) {
                // Chunked: an IN () with thousands of ids is a packet-size
                // problem, not a correctness one, but it fails loudly.
                foreach (array_chunk($missing->keys()->all(), 500) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->update([$column => null]);
                }
            }

            $cleared += $missing->count();
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] would clear ' : 'cleared ').$cleared.' stale path(s)');

        if ($cleared > 0 && ! $dryRun) {
            $this->line('Those documents regenerate on the next download.');
        }

        return self::SUCCESS;
    }
}
