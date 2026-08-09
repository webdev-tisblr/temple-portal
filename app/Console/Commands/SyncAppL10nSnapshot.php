<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keeps `resources/app-l10n/{gu,hi,en}.json` in step with the Flutter
 * app's `assets/l10n/{gu,hi,en}.json`.
 *
 * WHY THIS EXISTS
 * ---------------
 * The snapshot is the ONLY thing the admin "App Text Fixes" screen knows
 * about the app's wording: it builds the key picker from it and shows
 * "what the app displays today" from it. It is pure generated data — a
 * copy — but it was hand-copied, so it silently fell 40 keys and a dozen
 * values behind the app. The visible symptom is not an error: keys that
 * exist in the shipped app simply cannot be selected, and keys whose
 * wording changed display the OLD text, which reads to an admin as "the
 * panel isn't reflecting my updates".
 *
 * Regenerate (one-liner, run it as part of every store build):
 *
 *     php artisan app:sync-l10n-snapshot
 *
 * Detect staleness without writing (exit code 1 on drift, for CI/tests):
 *
 *     php artisan app:sync-l10n-snapshot --check
 *
 * The source defaults to the sibling app checkout (`../temple_app`), and
 * can be pointed elsewhere with `--from=` or `APP_L10N_SOURCE`.
 */
class SyncAppL10nSnapshot extends Command
{
    protected $signature = 'app:sync-l10n-snapshot
        {--from= : Path to the app l10n directory (default: ../temple_app/assets/l10n)}
        {--check : Report drift and exit 1 instead of writing the snapshot}';

    protected $description = "Regenerate resources/app-l10n/*.json from the Flutter app's assets/l10n/*.json";

    /** The locales the admin panel and the app both carry. */
    private const LOCALES = ['gu', 'hi', 'en'];

    public function handle(): int
    {
        $source = $this->sourceDirectory();

        if ($source === null) {
            return self::FAILURE;
        }

        $check = (bool) $this->option('check');
        $drifted = false;

        foreach (self::LOCALES as $locale) {
            $sourcePath = $source.DIRECTORY_SEPARATOR."{$locale}.json";
            $targetPath = resource_path("app-l10n/{$locale}.json");

            if (! is_file($sourcePath)) {
                $this->error("Missing app l10n file: {$sourcePath}");

                return self::FAILURE;
            }

            $raw = (string) file_get_contents($sourcePath);
            $fresh = json_decode($raw, true);

            // A half-written or non-flat file would poison the picker with
            // arrays where strings are expected, so refuse it outright
            // rather than writing a snapshot that breaks the admin form.
            if (! is_array($fresh) || $fresh === []) {
                $this->error("{$sourcePath} is not a non-empty JSON object.");

                return self::FAILURE;
            }

            foreach ($fresh as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    $this->error("{$sourcePath} must be a flat map of string keys to string values ({$key} is not).");

                    return self::FAILURE;
                }
            }

            $current = is_file($targetPath)
                ? (json_decode((string) file_get_contents($targetPath), true) ?: [])
                : [];

            $added = array_diff_key($fresh, $current);
            $removed = array_diff_key($current, $fresh);
            $changed = array_filter(
                array_intersect_key($fresh, $current),
                fn (string $value, string $key): bool => $current[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            $inSync = $added === [] && $removed === [] && $changed === [];

            $this->line(sprintf(
                '%s: snapshot %d → app %d  (+%d new, -%d removed, ~%d changed)',
                $locale,
                count($current),
                count($fresh),
                count($added),
                count($removed),
                count($changed),
            ));

            foreach (array_keys($added) as $key) {
                $this->line("    + {$key}");
            }
            foreach (array_keys($removed) as $key) {
                $this->line("    - {$key}");
            }
            foreach ($changed as $key => $value) {
                $this->line("    ~ {$key}: ".json_encode($current[$key], JSON_UNESCAPED_UNICODE).' → '.json_encode($value, JSON_UNESCAPED_UNICODE));
            }

            if ($inSync) {
                continue;
            }

            $drifted = true;

            if (! $check) {
                // Byte copy, not re-encode: the snapshot is meant to be
                // diff-identical to the app file, so re-serialising it
                // (escaping, key order, indentation) would produce noise
                // in every review even when nothing changed.
                file_put_contents($targetPath, $raw);
            }
        }

        if (! $drifted) {
            $this->info('Snapshot is in sync with the app.');

            return self::SUCCESS;
        }

        if ($check) {
            $this->error('resources/app-l10n/*.json is STALE. Run: php artisan app:sync-l10n-snapshot');

            return self::FAILURE;
        }

        $this->info('Snapshot regenerated from '.$source);

        return self::SUCCESS;
    }

    /** Resolve + sanity-check the app l10n directory. */
    private function sourceDirectory(): ?string
    {
        $from = $this->option('from')
            ?: env('APP_L10N_SOURCE')
            ?: base_path('../temple_app/assets/l10n');

        $resolved = realpath((string) $from);

        if ($resolved === false || ! is_dir($resolved)) {
            $this->error("App l10n directory not found: {$from}");
            $this->line('Pass --from=/path/to/temple_app/assets/l10n or set APP_L10N_SOURCE.');

            return null;
        }

        // `flutter build` copies l10n into build/**; those copies lag the
        // working tree, and syncing from one would re-introduce exactly
        // the staleness this command exists to remove.
        if (str_contains(str_replace('\\', '/', $resolved), '/build/')) {
            $this->error("Refusing to sync from a Flutter build directory: {$resolved}");

            return null;
        }

        return $resolved;
    }
}
