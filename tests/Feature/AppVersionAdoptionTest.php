<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * "How many devotees are on the new build?" — answered from the app_version
 * every device token already reports (2026-08-17).
 */
class AppVersionAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = AdminUser::create([
            'name' => 'Trustee',
            'email' => 'adopt-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh(), 'admin');
    }

    private function token(string $platform, ?string $version, int $daysSinceSeen = 0, bool $active = true): DeviceToken
    {
        return DeviceToken::create([
            'token' => Str::random(40),
            'platform' => $platform,
            'app_version' => $version,
            'is_active' => $active,
            'last_used_at' => now()->subDays($daysSinceSeen),
        ]);
    }

    public function test_it_reports_the_share_on_the_latest_version(): void
    {
        SystemSetting::updateOrCreate(['key' => 'app_latest_version'], ['value' => '1.5.0']);

        $this->token('android', '1.5.0');
        $this->token('android', '1.5.0');
        $this->token('android', '1.4.8');
        $this->token('ios', '1.5.0');

        // 3 of 4 on the latest.
        Livewire::test(AppVersionAdoption::class)
            ->assertSee('75%')
            ->assertSee('3 of 4 installs on 1.5.0');
    }

    public function test_stale_and_inactive_tokens_are_not_counted(): void
    {
        SystemSetting::updateOrCreate(['key' => 'app_latest_version'], ['value' => '1.5.0']);

        $this->token('android', '1.5.0');
        // Uninstalled / replaced handsets would otherwise sit in the
        // denominator forever and peg adoption below 100 for good.
        $this->token('android', '1.0.0', daysSinceSeen: 90);
        $this->token('android', '1.0.0', active: false);

        Livewire::test(AppVersionAdoption::class)
            ->assertSee('100%')
            ->assertSee('1 of 1 installs on 1.5.0');
    }

    public function test_it_says_so_when_no_latest_version_is_configured(): void
    {
        SystemSetting::where('key', 'app_latest_version')->delete();
        $this->token('android', '1.5.0');

        Livewire::test(AppVersionAdoption::class)
            ->assertSee('Set app_latest_version in System Settings');
    }

    public function test_it_handles_having_no_installs_at_all(): void
    {
        Livewire::test(AppVersionAdoption::class)
            ->assertSee('Reachable installs')
            ->assertSee('No active device tokens');
    }

    public function test_builds_too_old_to_report_a_version_are_called_out(): void
    {
        SystemSetting::updateOrCreate(['key' => 'app_latest_version'], ['value' => '1.5.0']);
        $this->token('android', '1.5.0');
        $this->token('android', null);

        Livewire::test(AppVersionAdoption::class)
            ->assertSee('1 install(s) too old to report a version');
    }
}
