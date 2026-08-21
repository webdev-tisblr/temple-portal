<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Delete R2 objects that no production database row points at.
 *
 * WHY THIS EXISTS (2026-08-21). The project's .env carries real Cloudflare
 * credentials, and a developer machine points at the SAME buckets as the
 * VPS. Any test that generated a receipt, invoice, greeting card or upload
 * without calling Storage::fake() therefore wrote a real object into the
 * live bucket, named after `fake()->name()`. Hundreds accumulated before
 * anyone traced where they came from. (The cause is fixed: Tests\TestCase
 * now fakes every R2 disk for every test, so omission can no longer reach
 * the live bucket. This command clears what was already written.)
 *
 * HOW IT DECIDES. Not by filename, not by date — by REACHABILITY. It walks
 * every model using HasManagedImages, reads the path columns that trait
 * declares, adds the handful of paths held in temple_system_settings, and
 * treats that as the set of objects the platform can still reach. Anything
 * in the bucket outside that set is unreferenced: a test artifact, or a
 * cache entry whose row is long gone. Both are safe to remove, and both are
 * exactly what we want gone.
 *
 * This is deliberately blind to WHICH test made a file. Matching on
 * "dummy-looking English names" would miss anything a future test invents
 * and would risk deleting a real devotee whose name happens to be English.
 * Reachability has neither failure mode.
 *
 * WHAT IS AT RISK. temple-private is a regenerable cache — receipts,
 * invoices and greeting cards rebuild on the next download, so a wrong
 * deletion there costs one regeneration. temple-public is permanent admin
 * content, so a wrong deletion there is NOT recoverable; that is why the
 * default is a dry run, why the report breaks down by folder, and why the
 * safety check below aborts rather than guesses.
 *
 *   php artisan r2:prune-orphans                 # report only
 *   php artisan r2:prune-orphans --disk=r2_private
 *   php artisan r2:prune-orphans --delete        # actually remove
 */
class PruneOrphanedR2Objects extends Command
{
    protected $signature = 'r2:prune-orphans
        {--delete : Actually delete. Without this the command only reports.}
        {--disk= : Limit to one disk (r2 or r2_private). Default: both.}
        {--list= : Print up to this many orphan paths per folder (default 5).}
        {--older-than=2 : Only consider objects older than this many days.}
        {--include-documents : Also clear unreferenced receipts, invoices and greeting cards (safe: nothing points at them, and downloads self-heal).}';

    protected $description = 'Remove R2 objects no database row references (test artifacts and dead cache entries)';

    /**
     * Never delete anything here, whatever the database says.
     *
     * These caches are not referenced by any row BY DESIGN — the platform
     * generates them, hands the devotee a CDN URL, and forgets. So "no row
     * points at this" is their normal state, not evidence of an orphan, and
     * reachability cannot tell a live one from a dead one. Worse, a status
     * card a devotee has already shared to WhatsApp lives only at that URL:
     * delete it and the share breaks permanently, with nothing to
     * regenerate from.
     *
     * Their own age-based sweepers expire them correctly (CleanStatusCards
     * at 30 days, CleanDarshanShareCards at 1), which is the right tool for
     * a cache. Leave them to it.
     */
    private const NEVER_REFERENCED = [
        'status-cards/',        // CleanStatusCards, 30 days
        'daily-darshan-cards/', // CleanDarshanShareCards, 1 day
        // Backups are addressed by listing, not by a stored path.
        'backups/',
    ];

    /**
     * Generated documents that ARE referenced by a row whenever they are
     * live: temple_orders.invoice_path, temple_seva_bookings.receipt_path
     * and .greeting_card_path, temple_receipts_80g.pdf_path, and so on.
     *
     * An unreferenced file here is therefore genuinely dead — overwhelmingly
     * a test artifact, because the suite generated a real PDF into the live
     * bucket and then rolled its database row back (that is the leak this
     * whole command exists to clean up).
     *
     * Deleting one cannot break a download. The endpoints self-heal on a
     * NULL path, and the sweepers NULL the column when they delete a file —
     * so "non-null means present" is the contract they rely on. An object
     * with no row pointing at it has no column to contradict, and no
     * devotee can reach it.
     *
     * Skipped by default anyway (their own 7-day sweeper would get there
     * eventually); --include-documents opts in to clearing them now.
     */
    private const REGENERABLE_DOCUMENTS = [
        'invoices/',
        'hall-invoices/',
        'seva-receipts/',
        'receipts/',
        'greeting-cards/',
    ];

    public function handle(): int
    {
        $delete = (bool) $this->option('delete');
        $includeDocuments = (bool) $this->option('include-documents');
        $sample = (int) ($this->option('list') ?? 5);

        // An upload lands in R2 a moment BEFORE its database row is
        // committed. Without a floor, a prune running in that window
        // deletes a perfectly good file the admin just uploaded, and the
        // record points at nothing. Two days costs nothing and removes
        // the race entirely.
        $cutoff = now()->subDays(max(0, (int) $this->option('older-than')))->getTimestamp();

        $disks = $this->option('disk') !== null
            ? [(string) $this->option('disk')]
            : ['r2', 'r2_private'];

        foreach ($disks as $disk) {
            if (! in_array($disk, ['r2', 'r2_private'], true)) {
                $this->error("Refusing to touch disk '{$disk}' — only r2 and r2_private are in scope.");

                return self::FAILURE;
            }
        }

        $referenced = $this->referencedPaths();

        // SAFETY RAIL. If the reference set came back empty or tiny, the
        // database is unreachable or a model changed shape — and acting on
        // that would empty the bucket. Stop instead of guessing.
        $total = array_sum(array_map('count', $referenced));
        if ($total < 50) {
            $this->error("Only {$total} referenced paths found. That is too few to trust — ".
                'the database is probably unreachable. Aborting without touching anything.');

            return self::FAILURE;
        }

        $this->info("Referenced by the database: {$total} objects");
        $this->newLine();

        $grandOrphans = 0;
        $grandBytes = 0;

        foreach ($disks as $disk) {
            $fs = Storage::disk($disk);
            $keep = $referenced[$disk] ?? [];

            $orphansByFolder = [];
            $bytesByFolder = [];
            $skipped = 0;
            $tooNew = 0;

            foreach ($fs->allFiles() as $path) {
                if (isset($keep[$path])) {
                    continue;
                }
                if (Str::startsWith($path, self::NEVER_REFERENCED)) {
                    $skipped++;

                    continue;
                }
                if (! $includeDocuments && Str::startsWith($path, self::REGENERABLE_DOCUMENTS)) {
                    $skipped++;

                    continue;
                }
                if ($fs->lastModified($path) > $cutoff) {
                    $tooNew++;

                    continue;
                }

                $folder = Str::contains($path, '/') ? Str::before($path, '/') : '(root)';
                $orphansByFolder[$folder][] = $path;
                $bytesByFolder[$folder] = ($bytesByFolder[$folder] ?? 0) + $fs->size($path);
            }

            $count = array_sum(array_map('count', $orphansByFolder));
            $bytes = array_sum($bytesByFolder);
            $grandOrphans += $count;
            $grandBytes += $bytes;

            $this->line("── {$disk} ".str_repeat('─', 46));
            if ($skipped > 0) {
                $this->line("  ({$skipped} objects left to their own age-based sweeper)");
            }
            if ($tooNew > 0) {
                $this->line("  ({$tooNew} objects newer than the age floor, left alone)");
            }
            if ($count === 0) {
                $this->info('  nothing unreferenced');
                $this->newLine();

                continue;
            }

            ksort($orphansByFolder);
            foreach ($orphansByFolder as $folder => $paths) {
                $this->line(sprintf('  %-26s %5d  (%s)', $folder, count($paths),
                    $this->humanBytes($bytesByFolder[$folder])));
                foreach (array_slice($paths, 0, $sample) as $p) {
                    $this->line("      {$p}");
                }
                if (count($paths) > $sample) {
                    $this->line('      … and '.(count($paths) - $sample).' more');
                }
            }

            $this->newLine();

            if ($delete) {
                $all = array_merge(...array_values($orphansByFolder));
                // Chunked: one delete call with thousands of keys can time
                // out against R2, and a partial failure mid-call is opaque.
                foreach (array_chunk($all, 200) as $chunk) {
                    $fs->delete($chunk);
                }
                $this->info("  deleted {$count} objects from {$disk}");
                $this->newLine();
            }
        }

        $this->newLine();
        $verb = $delete ? 'DELETED' : 'would delete';
        $this->warn("{$verb}: {$grandOrphans} objects, ".$this->humanBytes($grandBytes));

        if (! $delete && $grandOrphans > 0) {
            $this->line('Re-run with --delete to remove them.');
        }

        return self::SUCCESS;
    }

    /**
     * Every object path the platform can still reach, keyed by disk then
     * by path (a hash set — these lists run to thousands and we do a
     * membership test per bucket object).
     *
     * Scans EVERY text-ish column of EVERY table, not just the columns
     * models declare through HasManagedImages. That is deliberate, and it
     * is the second version of this method: the first walked only the
     * declared columns and would have deleted live content, because paths
     * hide in places no model declares —
     *
     *   • temple_seva_bookings.extra_data — a JSON blob holding uploads
     *     from a seva's custom fields (5 live paths on production)
     *   • temple_notifications.image_url — a plain column on a model that
     *     does not use the trait at all
     *   • temple_system_settings.value — the hero image, the popup
     *   • CMS page bodies — HTML with embedded cdn.… <img> sources
     *
     * temple-public is permanent, non-regenerable content. A false
     * positive there is unrecoverable, so the scan errs heavily towards
     * keeping: anything that LOOKS like an object path anywhere in the
     * database protects that object, even if the row is unrelated.
     *
     * @return array<string, array<string, true>>
     */
    private function referencedPaths(): array
    {
        $seen = [];

        // folder/filename.ext — optionally preceded by a CDN URL, which is
        // how paths appear inside CMS HTML. The folder list is open on
        // purpose: a new upload folder is protected without a code change.
        $pattern = '#(?:https?://[^\s"\'<>]*/)?([a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)+\.(?:png|jpe?g|webp|gif|svg|pdf|mp4|mov|ttf|otf))#i';

        foreach ($this->tableNames() as $table) {
            foreach ($this->scannableColumns($table) as $column) {
                DB::table($table)
                    ->whereNotNull($column)
                    ->orderBy($column)
                    ->pluck($column)
                    ->each(function ($value) use (&$seen, $pattern) {
                        $value = (string) $value;

                        // MySQL JSON columns store a path with the slash
                        // ESCAPED — "donation-extras\\/PKz….jpg" — and not
                        // consistently: temple_donations escapes it while
                        // temple_seva_bookings does not. Matching only the
                        // literal form found the seva uploads and missed
                        // every donation one, which flagged 61 real donor
                        // photographs (a birthday child's among them) as
                        // orphans in a bucket that has no undo. Normalise
                        // first, then match.
                        $value = str_replace('\\/', '/', $value);

                        if ($value === '' || ! Str::contains($value, '/')) {
                            return;
                        }
                        if (preg_match_all($pattern, $value, $m)) {
                            foreach ($m[1] as $hit) {
                                $seen[ltrim($hit, '/')] = true;
                            }
                        }
                    });
            }
        }

        // One set, applied to both disks. The two buckets do not share a
        // namespace, so protecting a path on both costs nothing and means
        // a file that moved between them is never orphaned by the move.
        return ['r2' => $seen, 'r2_private' => $seen];
    }

    /**
     * Every table in the current schema. SHOW TABLES rather than Doctrine,
     * which this project does not install.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        return array_map(
            fn ($row) => (string) array_values((array) $row)[0],
            DB::select('SHOW TABLES'),
        );
    }

    /**
     * Text-ish columns worth scanning. Numeric, date and boolean columns
     * cannot hold a path, and skipping them keeps a full-database scan to
     * something that finishes.
     *
     * @return list<string>
     */
    private function scannableColumns(string $table): array
    {
        $out = [];

        foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $col) {
            $type = strtolower((string) ($col->Type ?? ''));
            if (Str::contains($type, ['char', 'text', 'json', 'blob', 'enum'])) {
                $out[] = (string) $col->Field;
            }
        }

        return $out;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes = (int) ($bytes / 1024);
        }

        return "{$bytes} TB";
    }
}
