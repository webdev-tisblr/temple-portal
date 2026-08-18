<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keep `app_latest_version` in step with what is actually live on the store,
 * so devotees are told about a new build the moment it ships rather than
 * whenever someone remembers to update a setting (2026-08-17).
 *
 * Apple publishes the live version of any app through the public iTunes
 * Lookup API — no key, no account, but heavily CDN-cached (see the cache
 * buster in handle()). Google has no equivalent public endpoint
 * (the Play Developer API needs a service account), so ANDROID STAYS MANUAL.
 * In practice both stores carry the same version number from one pubspec, so
 * the iOS reading is a good proxy; where it is not, the admin sets the value
 * by hand.
 *
 * MOVES FORWARD ONLY. The job never lowers app_latest_version, which is what
 * makes "manual override" safe: an admin can bump it ahead of an Apple review
 * (or ahead of Android) and this will not drag it back down. It only ever
 * catches up.
 */
class SyncAppStoreVersion extends Command
{
    protected $signature = 'app:sync-store-version
        {--dry-run : Report what the store says without writing anything}';

    protected $description = 'Read the live iOS App Store version and advance app_latest_version to match';

    /** Where Apple publishes it. Public, unauthenticated. */
    private const LOOKUP_URL = 'https://itunes.apple.com/lookup';

    public function handle(): int
    {
        $bundleId = SystemSetting::getValue('app_ios_bundle_id', 'com.patadiyahanumanji.app');
        $current = trim(SystemSetting::getValue('app_latest_version', ''));

        try {
            $response = Http::timeout(15)->get(self::LOOKUP_URL, [
                'bundleId' => $bundleId,
                // The trust's audience is Indian, and Apple's catalogue is
                // per-storefront — a lookup with no country can miss an app
                // that is only published in some regions.
                'country' => SystemSetting::getValue('app_ios_store_country', 'in'),
                // Cache buster (2026-08-18). Apple serves this endpoint
                // through a CDN that keys on the full URL and IGNORES
                // Cache-Control/Pragma request headers — verified: the plain
                // URL kept returning 1.5.0 (35) for days after 1.5.1 went
                // live, while the same request with a varying param returned
                // 1.5.1 every time. Without this the daily job can sit on a
                // stale reading indefinitely, which is worse than not running
                // at all: it looks like Apple has not shipped yet.
                '_' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            // Never fail the scheduler over a third-party outage; the value
            // simply stays where it is until the next run.
            Log::warning('app:sync-store-version — lookup failed', [
                'bundle_id' => $bundleId,
                'error' => $e->getMessage(),
            ]);
            $this->warn('Store lookup failed: '.$e->getMessage());

            return self::SUCCESS;
        }

        if (! $response->successful()) {
            Log::warning('app:sync-store-version — lookup returned an error', [
                'bundle_id' => $bundleId,
                'status' => $response->status(),
            ]);
            $this->warn('Store lookup returned HTTP '.$response->status());

            return self::SUCCESS;
        }

        // Apple returns the app's MARKETING version verbatim, and this app's
        // is literally "1.5.0 (35)" — the build number is part of the string
        // in App Store Connect. Storing that raw would be actively harmful:
        // the app strips non-digits when comparing, reading it as 1.5.35, so
        // every devotee already on 1.5.0 would be told an update exists,
        // forever. Take the leading dotted-numeric run and nothing else.
        $storeVersion = self::normalise((string) ($response->json('results.0.version') ?? ''));

        if ($storeVersion === '') {
            // An unpublished or region-restricted app returns resultCount 0.
            $this->warn("No App Store listing found for {$bundleId}. Nothing changed.");

            return self::SUCCESS;
        }

        $this->info("App Store reports {$storeVersion}; app_latest_version is ".($current === '' ? '(unset)' : $current));

        SystemSetting::setValue('app_latest_version_ios', $storeVersion);

        if ($current !== '' && ! self::isNewer($storeVersion, $current)) {
            $this->info('Already up to date — nothing to advance.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would set app_latest_version to {$storeVersion}.");

            return self::SUCCESS;
        }

        SystemSetting::setValue('app_latest_version', $storeVersion);
        SystemSetting::forgetCache();

        $this->info("app_latest_version advanced to {$storeVersion}.");
        Log::info('app:sync-store-version advanced the latest version', [
            'from' => $current === '' ? null : $current,
            'to' => $storeVersion,
        ]);

        return self::SUCCESS;
    }

    /**
     * The leading dotted-numeric run of a version string, and nothing else.
     *
     * "1.5.0 (35)" → "1.5.0", "v2.0" → "2.0", "1.5.0+35" → "1.5.0". Anything
     * with no numeric prefix at all → "". Naively stripping non-digits would
     * glue the build number onto the patch segment and turn 1.5.0 (35) into
     * 1.5.35, which is worse than useless — it reads as a NEWER version.
     */
    public static function normalise(string $version): string
    {
        return preg_match('/\d+(?:\.\d+)*/', $version, $m) === 1 ? $m[0] : '';
    }

    /**
     * Semver-ish "is $candidate newer than $current", comparing numeric parts
     * left to right. Mirrors AppConfigService.isNewer in the Flutter app —
     * a string compare would call 1.10.0 older than 1.9.0.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $parse = static function (string $v): array {
            $normalised = self::normalise($v);

            return $normalised === ''
                ? [0]
                : array_map('intval', explode('.', $normalised));
        };

        $a = $parse($candidate);
        $b = $parse($current);
        $length = max(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $left = $a[$i] ?? 0;
            $right = $b[$i] ?? 0;
            if ($left !== $right) {
                return $left > $right;
            }
        }

        return false;
    }
}
