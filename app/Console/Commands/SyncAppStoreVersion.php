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
 * Lookup API — no key, no account. Google has no equivalent public endpoint
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

        $storeVersion = trim((string) ($response->json('results.0.version') ?? ''));

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
     * Semver-ish "is $candidate newer than $current", comparing numeric parts
     * left to right. Mirrors AppConfigService.isNewer in the Flutter app —
     * a string compare would call 1.10.0 older than 1.9.0.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $parse = static fn (string $v): array => array_map(
            'intval',
            explode('.', preg_replace('/[^0-9.]/', '', $v) ?: '0')
        );

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
