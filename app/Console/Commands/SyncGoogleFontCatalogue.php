<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Refresh the bundled Google Fonts catalogue.
 *
 * The catalogue is a committed file, not a runtime fetch — the admin's font
 * dropdown must render instantly and must not break when Google is slow or
 * the box has no outbound network. This command is how that file is updated:
 * run it, eyeball the diff, commit.
 *
 * Google adds a handful of families a month, so a refresh once or twice a year
 * is plenty. It is deliberately NOT scheduled: a cron that rewrites a
 * committed file would silently drift from git.
 */
class SyncGoogleFontCatalogue extends Command
{
    protected $signature = 'fonts:sync-catalogue {--dry-run : Report the change without writing the file}';

    protected $description = 'Refresh resources/data/google-fonts.json from the Google Fonts metadata endpoint';

    /** Scripts we care about flagging — the temple publishes in these. */
    private const INDIC_SUBSETS = ['gujarati', 'devanagari'];

    private const ENDPOINT = 'https://fonts.google.com/metadata/fonts';

    public function handle(): int
    {
        $path = resource_path('data/google-fonts.json');
        $before = is_file($path) ? count((array) json_decode((string) file_get_contents($path), true)) : 0;

        $response = Http::timeout(60)->get(self::ENDPOINT);

        if (! $response->successful()) {
            $this->error('Google returned HTTP '.$response->status().' — catalogue left unchanged.');

            return self::FAILURE;
        }

        $families = $response->json('familyMetadataList');

        if (! is_array($families) || $families === []) {
            $this->error('No families in the response — catalogue left unchanged.');

            return self::FAILURE;
        }

        $rows = collect($families)
            ->map(function (array $family): array {
                $faces = (array) ($family['fonts'] ?? []);

                // Keys look like "400" and "400i" — the weight is the digits.
                $weights = collect(array_keys($faces))
                    ->map(fn ($key) => (int) rtrim((string) $key, 'i'))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $indic = array_values(array_intersect(
                    (array) ($family['subsets'] ?? []),
                    self::INDIC_SUBSETS,
                ));

                return array_filter([
                    'f' => (string) $family['family'],
                    'c' => (string) ($family['category'] ?? ''),
                    'w' => $weights ?: [400],
                    'i' => collect(array_keys($faces))->contains(fn ($k) => str_ends_with((string) $k, 'i')) ? 1 : 0,
                    'p' => (int) ($family['popularity'] ?? 99999),
                    's' => $indic,
                ], fn ($value, $key) => $key !== 's' || $value !== [], ARRAY_FILTER_USE_BOTH);
            })
            // Popularity order, so the top of the dropdown is the useful end.
            ->sortBy('p')
            ->values()
            ->all();

        $this->info(sprintf('%d families (was %d). Indic-capable: %d.',
            count($rows), $before, collect($rows)->filter(fn ($r) => ! empty($r['s']))->count()));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE));
        Cache::forget('google-fonts.catalogue.v1');

        $this->info('Wrote '.$path.' — review the diff and commit it.');

        return self::SUCCESS;
    }
}
