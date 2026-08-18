<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SyncAppStoreVersion;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * app_latest_version tracks what is actually live on the App Store, so a
 * release reaches devotees the day it ships (2026-08-17).
 *
 * The forward-only rule is the important one: it is what makes a manual
 * override safe, so it gets its own tests in both directions.
 */
class SyncAppStoreVersionTest extends TestCase
{
    use RefreshDatabase;

    private function appleReturns(?string $version): void
    {
        Http::fake([
            'itunes.apple.com/*' => Http::response(
                $version === null
                    ? ['resultCount' => 0, 'results' => []]
                    : ['resultCount' => 1, 'results' => [['version' => $version]]]
            ),
        ]);
    }

    public function test_it_advances_to_a_newer_store_version(): void
    {
        SystemSetting::setValue('app_latest_version', '1.4.8');
        $this->appleReturns('1.5.0');

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.5.0', SystemSetting::getValue('app_latest_version'));
        $this->assertSame('1.5.0', SystemSetting::getValue('app_latest_version_ios'));
    }

    public function test_the_lookup_is_cache_busted(): void
    {
        // Apple serves this endpoint through a CDN that keys on the full URL
        // and ignores Cache-Control/Pragma on the request — verified against
        // the live API, where the plain URL kept returning 1.5.0 for days
        // after 1.5.1 shipped while a varying param returned 1.5.1 every
        // time. A stale reading is worse than no reading: it looks like Apple
        // simply has not published yet.
        $this->appleReturns('1.5.0');

        $this->artisan('app:sync-store-version')->assertSuccessful();

        Http::assertSent(fn ($request) => ! empty($request->data()['_'] ?? null));
    }

    public function test_it_never_moves_the_version_backwards(): void
    {
        // The admin bumped it ahead of Apple's review — perhaps Android is
        // already live. The job must not drag it back.
        SystemSetting::setValue('app_latest_version', '1.6.0');
        $this->appleReturns('1.5.0');

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.6.0', SystemSetting::getValue('app_latest_version'));
        // It still records what iOS actually has, for visibility.
        $this->assertSame('1.5.0', SystemSetting::getValue('app_latest_version_ios'));
    }

    public function test_ten_sorts_above_nine_rather_than_alphabetically(): void
    {
        SystemSetting::setValue('app_latest_version', '1.9.0');
        $this->appleReturns('1.10.0');

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.10.0', SystemSetting::getValue('app_latest_version'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        SystemSetting::setValue('app_latest_version', '1.4.8');
        $this->appleReturns('1.5.0');

        $this->artisan('app:sync-store-version', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('1.4.8', SystemSetting::getValue('app_latest_version'));
    }

    public function test_an_unlisted_app_changes_nothing(): void
    {
        SystemSetting::setValue('app_latest_version', '1.4.8');
        $this->appleReturns(null);

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.4.8', SystemSetting::getValue('app_latest_version'));
    }

    public function test_a_store_outage_is_not_a_scheduler_failure(): void
    {
        // Never let a third-party wobble fail the nightly run — the value
        // simply stays put until the next one.
        SystemSetting::setValue('app_latest_version', '1.4.8');
        Http::fake(['itunes.apple.com/*' => Http::response('', 503)]);

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.4.8', SystemSetting::getValue('app_latest_version'));
    }

    /**
     * Apple returns this app's marketing version as the literal string
     * "1.5.0 (35)" — the build number is part of it in App Store Connect.
     * Storing that raw would make every devotee already on 1.5.0 see a
     * permanent "update available", because the comparison strips non-digits
     * and reads it as 1.5.35. This is the single most important case here.
     */
    public function test_it_strips_the_build_number_apple_returns(): void
    {
        SystemSetting::setValue('app_latest_version', '1.5.0');
        $this->appleReturns('1.5.0 (35)');

        $this->artisan('app:sync-store-version')->assertSuccessful();

        $this->assertSame('1.5.0', SystemSetting::getValue('app_latest_version'),
            'the parenthesised build must never reach the setting');
        $this->assertSame('1.5.0', SystemSetting::getValue('app_latest_version_ios'));
    }

    public function test_a_parenthesised_build_is_not_mistaken_for_a_newer_release(): void
    {
        $this->assertFalse(SyncAppStoreVersion::isNewer('1.5.0 (35)', '1.5.0'));
        $this->assertTrue(SyncAppStoreVersion::isNewer('1.5.1 (36)', '1.5.0 (35)'));
    }

    /** @return array<string, array{string, string}> */
    public static function normaliseProvider(): array
    {
        return [
            'apple build suffix' => ['1.5.0 (35)', '1.5.0'],
            'plus suffix' => ['1.5.0+35', '1.5.0'],
            'v prefix' => ['v2.0', '2.0'],
            'plain' => ['1.10.3', '1.10.3'],
            'trailing text' => ['3.1 beta', '3.1'],
            'no digits at all' => ['unreleased', ''],
        ];
    }

    #[DataProvider('normaliseProvider')]
    public function test_normalise(string $raw, string $expected): void
    {
        $this->assertSame($expected, SyncAppStoreVersion::normalise($raw));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function versionProvider(): array
    {
        return [
            'patch bump' => ['1.5.1', '1.5.0', true],
            'same' => ['1.5.0', '1.5.0', false],
            'older' => ['1.4.9', '1.5.0', false],
            'double digit minor' => ['1.10.0', '1.9.9', true],
            'shorter but newer' => ['2.0', '1.9.9', true],
            'build suffix ignored' => ['1.5.0', '1.5.0+35', false],
        ];
    }

    #[DataProvider('versionProvider')]
    public function test_version_comparison(string $candidate, string $current, bool $expected): void
    {
        $this->assertSame($expected, SyncAppStoreVersion::isNewer($candidate, $current));
    }
}
