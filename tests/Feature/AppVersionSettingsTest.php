<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Filament\Widgets\AppVersionAdoption;
use App\Models\AdminUser;
use App\Models\DeviceToken;
use App\Models\SystemSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `app_latest_version` drives the App version adoption widget and the app's
 * own "Update available" prompt, but had no field anywhere in the admin —
 * correcting a stale value meant a SQL console (2026-08-18).
 *
 * The validation matters as much as the field: the app compares versions
 * numerically, so "1.5.1 (36)" saved here reads as 1.5.36 and tells every
 * devotee to update forever.
 */
class AppVersionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = AdminUser::create([
            'name' => 'Version Admin',
            'email' => 'version-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh(), 'admin');
    }

    public function test_the_latest_version_can_be_set_from_the_settings_page(): void
    {
        Livewire::test(SystemSettings::class)
            ->assertFormFieldExists('app_latest_version')
            ->assertFormFieldExists('app_min_version')
            // trust_name is a required field elsewhere on the page — a save
            // from a bare test DB fails on it, not on anything here.
            ->fillForm([
                'trust_name' => 'Shree Patadiya Hanumanji Seva Trust',
                'app_latest_version' => '1.5.1',
                'app_min_version' => '1.4.0',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1.5.1', SystemSetting::getValue('app_latest_version'));
        $this->assertSame('1.4.0', SystemSetting::getValue('app_min_version'));
    }

    public function test_a_build_number_is_rejected_rather_than_stored(): void
    {
        Livewire::test(SystemSettings::class)
            ->fillForm([
                'trust_name' => 'Shree Patadiya Hanumanji Seva Trust',
                'app_latest_version' => '1.5.1 (36)',
            ])
            ->call('save')
            ->assertHasFormErrors(['app_latest_version']);

        $this->assertSame('', SystemSetting::getValue('app_latest_version'));
    }

    public function test_the_readout_flags_an_app_store_version_ahead_of_the_setting(): void
    {
        SystemSetting::setValue('app_latest_version', '1.5.0');
        SystemSetting::setValue('app_latest_version_ios', '1.5.1');

        $html = (string) Livewire::test(SystemSettings::class)
            ->instance()
            ->renderAppStoreVersionReadout();

        $this->assertStringContainsString('Apple is AHEAD', $html);
    }

    public function test_the_readout_treats_being_ahead_of_apple_as_normal(): void
    {
        // The state during an Apple review, or after a Play-only release.
        SystemSetting::setValue('app_latest_version', '1.5.1');
        SystemSetting::setValue('app_latest_version_ios', '1.5.0');

        $html = (string) Livewire::test(SystemSettings::class)
            ->instance()
            ->renderAppStoreVersionReadout();

        $this->assertStringContainsString('Ahead of Apple', $html);
        $this->assertStringNotContainsString('Apple is AHEAD', $html);
    }

    public function test_the_adoption_widget_follows_the_setting(): void
    {
        $token = fn (string $platform, string $version) => DeviceToken::create([
            'token' => Str::random(40),
            'platform' => $platform,
            'app_version' => $version,
            'is_active' => true,
            'last_used_at' => now(),
        ]);

        $token('android', '1.5.1');
        $token('android', '1.5.0');
        $token('ios', '1.5.0');

        SystemSetting::setValue('app_latest_version', '1.5.0');
        $this->assertStringContainsString('67%', $this->widgetHtml());

        // Correcting the setting is the whole point — the same devices must
        // now read as one third updated, not two thirds.
        SystemSetting::setValue('app_latest_version', '1.5.1');
        $this->assertStringContainsString('33%', $this->widgetHtml());
    }

    private function widgetHtml(): string
    {
        return (string) Livewire::test(AppVersionAdoption::class)->html();
    }
}
