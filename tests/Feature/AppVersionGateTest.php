<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\AdminUser;
use App\Models\SystemSetting;
use App\Support\AppVersion;
use App\Support\AppVersionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The force-update gate decides whether a devotee can open the app at all,
 * so the two ways it can go wrong are not symmetric:
 *
 *   too permissive → someone stays on an old build a while longer
 *   too strict     → a platform is locked out of the app with no recourse
 *
 * Everything here is written from that asymmetry. The gate must fail OPEN on
 * anything blank, unparseable or unconfigured, and a minimum meant for one
 * platform must never reach the other.
 */
class AppVersionGateTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, string $value): void
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'app']);
    }

    private function config(): array
    {
        return $this->getJson('/api/v1/app-config')->assertOk()->json('data');
    }

    // ── Fail open ────────────────────────────────────────────────────

    public function test_an_unconfigured_install_blocks_nobody(): void
    {
        $data = $this->config();

        $this->assertSame('', $data['min_supported_version']);
        $this->assertSame('', $data['min_supported_version_android']);
        $this->assertSame('', $data['min_supported_version_ios']);
        $this->assertFalse($data['update_required']);
        $this->assertFalse($data['force_latest_version']);
    }

    public function test_a_blank_setting_is_not_read_as_version_zero(): void
    {
        // The seeder writes these rows as '', and getValue returns the blank
        // ROW rather than the default it was handed. Read as "0" that would
        // be below nothing, which is harmless — but read as a real minimum
        // by some future caller it would not be. Blank stays blank.
        $this->set('app_min_version', '');
        $this->set('app_min_version_android', '');

        $this->assertSame('', AppVersionGate::minFor(AppVersionGate::PLATFORM_ANDROID));
        $this->assertSame('', AppVersionGate::sharedMin());
    }

    public function test_an_unparseable_setting_blocks_nobody(): void
    {
        // The admin field validates, but a value can also arrive by SQL or
        // an old seeder. "v-next" must not become a minimum of 0 or a crash.
        $this->set('app_min_version', 'not a version');

        $this->assertSame('', AppVersionGate::sharedMin());
        $this->assertSame('', $this->config()['min_supported_version']);
    }

    // ── Per-platform resolution ──────────────────────────────────────

    public function test_a_platform_minimum_overrides_the_shared_one(): void
    {
        $this->set('app_min_version', '1.5.0');
        $this->set('app_min_version_android', '1.5.4');

        $this->assertSame('1.5.4', AppVersionGate::minFor(AppVersionGate::PLATFORM_ANDROID));
        // iOS was never given its own, so it keeps the shared value.
        $this->assertSame('1.5.0', AppVersionGate::minFor(AppVersionGate::PLATFORM_IOS));
    }

    public function test_forcing_android_does_not_reach_iphones(): void
    {
        // THE point of the split. Android ships in hours, Apple review takes
        // days; forcing a version Apple has not approved would wall every
        // iPhone devotee behind an Update button that cannot help them.
        $this->set('app_min_version_android', '1.5.4');

        $data = $this->config();

        $this->assertSame('1.5.4', $data['min_supported_version_android']);
        $this->assertSame('', $data['min_supported_version_ios'], 'iPhones must be untouched');
    }

    public function test_legacy_clients_are_served_the_most_permissive_minimum(): void
    {
        // Builds already in the field read only `min_supported_version` and
        // cannot tell us their platform. They get the LOWER of the two, so a
        // tightening meant for Android cannot wall an old iPhone build.
        $this->set('app_min_version_android', '1.5.4');
        $this->set('app_min_version_ios', '1.5.0');

        $this->assertSame('1.5.0', $this->config()['min_supported_version']);
    }

    public function test_a_platform_with_no_minimum_keeps_legacy_clients_open(): void
    {
        // Android forced, iOS not configured at all: the legacy key must
        // report "no minimum", not Android's.
        $this->set('app_min_version_android', '1.5.4');

        $this->assertSame('', $this->config()['min_supported_version']);
    }

    // ── The comparison itself ────────────────────────────────────────

    public function test_versions_compare_numerically_not_as_strings(): void
    {
        // A string compare calls 1.10.0 older than 1.9.0 and would let a
        // whole release through the gate.
        $this->assertTrue(AppVersion::isNewer('1.10.0', '1.9.0'));
        $this->assertFalse(AppVersion::isNewer('1.9.0', '1.10.0'));
        $this->assertFalse(AppVersion::isNewer('1.5.2', '1.5.2'));
        $this->assertTrue(AppVersion::isNewer('1.5.2', '1.5'));
    }

    public function test_build_numbers_are_stripped_before_comparing(): void
    {
        // Apple returns "1.5.0 (35)"; Flutter carries "1.5.2+37". Compared
        // raw, a build number reads as a patch segment — "1.5.1 (36)" would
        // become 1.5.36 and tell every devotee to update forever.
        $this->assertSame('1.5.0', AppVersion::normalise('1.5.0 (35)'));
        $this->assertSame('1.5.2', AppVersion::normalise('1.5.2+37'));
        $this->assertSame('1.5.4', AppVersion::normalise('v1.5.4'));
        $this->assertSame('', AppVersion::normalise('none'));
    }

    public function test_lower_picks_the_smaller_of_two_present_versions(): void
    {
        $this->assertSame('1.5.0', AppVersion::lower('1.5.4', '1.5.0'));
        $this->assertSame('1.5.0', AppVersion::lower('1.5.0', '1.5.4'));
        $this->assertSame('1.9.0', AppVersion::lower('1.10.0', '1.9.0'));
    }

    public function test_an_unset_platform_makes_the_shared_minimum_unset(): void
    {
        // Caught by test_a_platform_with_no_minimum_keeps_legacy_clients_open
        // during development: an earlier cut treated blank as "no opinion"
        // and let the configured platform win, which would have walled every
        // old iPhone build through the legacy key. Blank is a floor of
        // NOTHING, and the most permissive answer always wins here.
        $this->set('app_min_version_android', '1.5.4');
        $this->assertSame('', AppVersionGate::sharedMin());

        $this->set('app_min_version_ios', '1.5.0');
        $this->assertSame('1.5.0', AppVersionGate::sharedMin());
    }

    // ── The store link out of the wall ───────────────────────────────

    public function test_a_blank_store_url_falls_back_to_the_real_listing(): void
    {
        // Worst case this guards: a force-update wall whose Update button
        // has no URL to open — no way out of the app at all. The seeder
        // writes these rows blank, and a blank ROW beats getValue's default.
        $this->set('app_android_store_url', '');
        $this->set('app_ios_store_url', '');

        $data = $this->config();

        $this->assertStringContainsString('play.google.com', $data['android_store_url']);
        $this->assertStringContainsString('apps.apple.com', $data['ios_store_url']);
    }

    // ── The emergency stop ───────────────────────────────────────────

    public function test_the_emergency_stop_is_off_unless_explicitly_set(): void
    {
        $this->assertFalse($this->config()['update_required']);

        $this->set('app_update_required', '1');
        $this->assertTrue($this->config()['update_required']);

        $this->set('app_update_required', '0');
        $this->assertFalse($this->config()['update_required']);
    }

    // ── The admin page ───────────────────────────────────────────────

    public function test_the_settings_page_renders_the_new_fields_and_the_impact_readout(): void
    {
        // Filament resolves closure params by type-hint or canonical name
        // only, so a bad signature throws at RENDER time — php -l never sees
        // it and the live page 500s. Mount the real component.
        $admin = AdminUser::create([
            'name' => 'Version Gate Admin',
            'email' => 'version-gate-'.Str::random(8).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'admin']
        );
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh(), 'admin');

        Livewire::test(SystemSettings::class)
            ->assertOk()
            ->assertFormFieldExists('app_min_version_android')
            ->assertFormFieldExists('app_min_version_ios')
            // The panic switch had no UI at all before — SQL only.
            ->assertFormFieldExists('app_update_required');
    }

    public function test_the_impact_readout_names_who_a_minimum_would_block(): void
    {
        // The whole hazard is that a gate is invisible until it is too late.
        $this->set('app_min_version_android', '1.5.4');

        $page = new SystemSettings;
        $page->data = ['app_min_version_android' => '1.5.4', 'app_min_version' => ''];

        $html = (string) $page->renderMinVersionImpact();

        $this->assertStringContainsString('Android', $html);
        $this->assertStringContainsString('1.5.4', $html);
        // iOS was never configured, so it must read as "nobody blocked".
        $this->assertStringContainsString('nobody is blocked', $html);
    }

    // ── The keys shipped apps depend on ──────────────────────────────

    public function test_the_response_keeps_every_key_shipped_builds_read(): void
    {
        // ~2,500 devices are running builds that predate the split. Dropping
        // or renaming any of these breaks them in the field, where they
        // cannot be fixed.
        $data = $this->config();

        foreach ([
            'min_supported_version', 'latest_version', 'android_store_url',
            'ios_store_url', 'update_required', 'force_latest_version',
            'ios_native_donations_enabled', 'donate_web_url',
            'whatsapp_group_url', 'whatsapp_group_enabled',
            'instagram_url', 'instagram_enabled',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "shipped apps read data.{$key}");
        }
    }
}
