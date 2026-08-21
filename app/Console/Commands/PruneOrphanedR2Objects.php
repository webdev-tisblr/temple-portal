<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Concerns\HasManagedImages;
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
        {--list= : Print up to this many orphan paths per folder (default 5).}';

    protected $description = 'Remove R2 objects no database row references (test artifacts and dead cache entries)';

    /**
     * Never delete anything under these prefixes, whatever the DB says.
     * They are written and read by paths that do not go through a model
     * column, so "unreferenced" would be a false positive.
     */
    private const PROTECTED_PREFIXES = [
        // Backups are addressed by listing, not by a stored path.
        'backups/',
    ];

    public function handle(): int
    {
        $delete = (bool) $this->option('delete');
        $sample = (int) ($this->option('list') ?? 5);

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

            foreach ($fs->allFiles() as $path) {
                if (isset($keep[$path])) {
                    continue;
                }
                if (Str::startsWith($path, self::PROTECTED_PREFIXES)) {
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
     * @return array<string, array<string, true>>
     */
    private function referencedPaths(): array
    {
        $out = ['r2' => [], 'r2_private' => []];

        foreach ($this->modelsWithManagedImages() as $class) {
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $class;

            $method = new \ReflectionMethod($class, 'managedImages');
            $method->setAccessible(true);
            /** @var array<string, string> $columns */
            $columns = $method->invoke($instance);

            foreach ($columns as $column => $disk) {
                if (! array_key_exists($disk, $out)) {
                    continue;
                }
                // Straight to the query builder: no model hydration, no
                // accessors, no global scopes hiding rows whose file is
                // still very much on the bucket.
                DB::table($instance->getTable())
                    ->whereNotNull($column)
                    ->where($column, '<>', '')
                    ->orderBy($column)
                    ->pluck($column)
                    ->each(function ($path) use (&$out, $disk) {
                        $out[$disk][ltrim((string) $path, '/')] = true;
                    });
            }
        }

        // Settings hold a couple of paths directly (hero image, popup,
        // live-darshan placeholder). They belong to no model, so the walk
        // above cannot see them.
        foreach (DB::table('temple_system_settings')->pluck('value') as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! Str::contains($value, ' ') && Str::contains($value, '/')) {
                $out['r2'][ltrim($value, '/')] = true;
                $out['r2_private'][ltrim($value, '/')] = true;
            }
        }

        return $out;
    }

    /**
     * Every model class that opts into HasManagedImages. Discovered by
     * scanning app/Models rather than hand-listed, so a model added later
     * is protected automatically instead of having its files pruned.
     *
     * @return list<class-string>
     */
    private function modelsWithManagedImages(): array
    {
        $found = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            if (in_array(HasManagedImages::class, class_uses_recursive($class), true)) {
                $found[] = $class;
            }
        }

        return $found;
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
