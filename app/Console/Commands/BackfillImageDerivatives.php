<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyDarshanPhoto;
use App\Models\GalleryImage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the thumbnail/medium renditions for rows that predate
 * HasImageDerivatives (every production row as of 2026-08-09).
 *
 * Safe to run against production, and designed to be run repeatedly:
 *
 *  • IDEMPOTENT — a row whose rendition columns are already populated is
 *    skipped in SQL, so it is never downloaded and never re-encoded. A
 *    second run reports 0 generated. `--force` overrides, and because
 *    derivative keys are deterministic it overwrites in place rather than
 *    accumulating objects.
 *  • RESUMABLE — completed rows drop out of the query, so an interrupted
 *    run resumes simply by being re-run. `--from-id` skips ahead manually.
 *  • BOUNDED — `--limit` caps the work per invocation and `--sleep` paces
 *    it, so a first pass over ~95 originals (seven of them 200 MP) can be
 *    spread out instead of pinning a 2-vCPU box.
 *  • FAIL-SOFT — one unreadable or oversized original logs, counts as a
 *    failure and the batch continues. Originals are never modified or
 *    deleted; only new objects under `<dir>/derivatives/` are written.
 */
class BackfillImageDerivatives extends Command
{
    protected $signature = 'images:backfill-derivatives
        {--target=* : Restrict to these targets (gallery, darshan). Default: all}
        {--limit=0 : Stop after this many rows across all targets (0 = no limit)}
        {--chunk=50 : Rows fetched per query}
        {--from-id=0 : Only rows with an id >= this (manual resume)}
        {--sleep=0 : Seconds to pause between rows, to pace a production run}
        {--force : Regenerate even when the rendition columns are already set}
        {--dry-run : List what would be generated and change nothing}';

    protected $description = 'Generate missing thumbnail/medium image renditions for existing rows';

    /** Target name => model class. Every class must use HasImageDerivatives. */
    private const TARGETS = [
        'gallery' => GalleryImage::class,
        'darshan' => DailyDarshanPhoto::class,
    ];

    public function handle(): int
    {
        $targets = $this->option('target') ?: array_keys(self::TARGETS);
        $unknown = array_diff($targets, array_keys(self::TARGETS));

        if ($unknown !== []) {
            $this->error('Unknown target(s): '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys(self::TARGETS)));

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));
        $fromId = max(0, (int) $this->option('from-id'));
        $sleep = max(0, (int) $this->option('sleep'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $generated = 0;
        $failed = 0;
        $seen = 0;

        foreach ($targets as $target) {
            /** @var class-string<Model> $class */
            $class = self::TARGETS[$target];
            $model = new $class;

            $this->line('');
            $this->info("── {$target} ({$class})");

            $query = $class::query()->where($model->getKeyName(), '>=', $fromId);

            if (! $force) {
                $query->where(function (Builder $q) use ($model): void {
                    foreach ($this->derivativeColumns($model) as $source => $columns) {
                        $q->orWhere(function (Builder $qq) use ($source, $columns): void {
                            $qq->whereNotNull($source)->where($source, '!=', '');
                            $qq->where(function (Builder $qqq) use ($columns): void {
                                foreach ($columns as $column) {
                                    $qqq->orWhereNull($column)->orWhere($column, '=', '');
                                }
                            });
                        });
                    }
                });
            }

            $total = (clone $query)->count();
            $this->line("   {$total} row(s) need work.");

            if ($total === 0) {
                continue;
            }

            $query->orderBy($model->getKeyName())->chunkById($chunk, function ($rows) use (&$generated, &$failed, &$seen, $limit, $sleep, $force, $dryRun): bool {
                foreach ($rows as $row) {
                    if ($limit > 0 && $seen >= $limit) {
                        $this->comment("   Reached --limit={$limit}; stopping.");

                        return false;
                    }

                    $seen++;

                    if ($dryRun) {
                        $this->line("   [dry-run] #{$row->getKey()} would be processed.");

                        continue;
                    }

                    $error = $row->tryGenerateImageDerivatives($force);

                    if ($error !== null) {
                        $failed++;
                        $this->warn("   #{$row->getKey()} FAILED: {$error}");
                    } else {
                        $generated++;
                        $this->line("   #{$row->getKey()} ok  thumb={$row->thumbnail_path}");
                    }

                    // Release the decoded bitmap before the next row: the
                    // 200 MP originals are the whole reason this command
                    // exists, and two of them resident at once is avoidable.
                    gc_collect_cycles();

                    if ($sleep > 0) {
                        sleep($sleep);
                    }
                }

                return true;
            }, $model->getKeyName());
        }

        $this->line('');
        $this->info("Done. generated={$generated} failed={$failed} scanned={$seen}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * imageDerivatives() is protected on the model (it is a config hook,
     * not API); read it once here so the command can build the "still
     * missing" WHERE clause without every model exposing it publicly.
     *
     * @return array<string, array<string, string>>
     */
    private function derivativeColumns(Model $model): array
    {
        $reflection = new \ReflectionMethod($model, 'imageDerivatives');
        $reflection->setAccessible(true);

        return $reflection->invoke($model);
    }
}
