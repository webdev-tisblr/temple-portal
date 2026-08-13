<?php

namespace Tests\Feature;

use App\Filament\Widgets\ComingSoonToggleWidget;
use App\Models\AdminUser;
use App\Models\SystemSetting;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Scheduled launch (2026-08-13).
 *
 * The rule that matters: the MIDDLEWARE opens the site at the launch
 * instant, not the cron. `site:launch` only makes the stored state agree
 * afterwards. If the scheduler is wedged, a deploy is mid-flight, or someone
 * kills the cron, the doors still open at the advertised moment.
 */
class LaunchCountdownTest extends TestCase
{
    use RefreshDatabase;

    private function comingSoon(bool $on, ?string $launchAt = null): void
    {
        SystemSetting::updateOrCreate(['key' => 'coming_soon_mode'],
            ['value' => $on ? '1' : '0', 'group' => 'system']);

        if ($launchAt === null) {
            SystemSetting::where('key', 'launch_at')->delete();
        } else {
            SystemSetting::updateOrCreate(['key' => 'launch_at'],
                ['value' => $launchAt, 'group' => 'system']);
        }

        SystemSetting::forgetCache();
        Cache::forget('system.coming_soon_mode');
        Cache::forget('system.launch_at');
    }

    public function test_the_site_is_hidden_before_the_launch_moment(): void
    {
        $this->comingSoon(true, now()->addDay()->format('Y-m-d H:i:s'));

        $this->get('/')->assertStatus(503)->assertSee(__('comingsoon.countdown_title'));
    }

    /** THE case. The flag still says 'on'; the clock says otherwise. */
    public function test_the_site_opens_at_the_launch_moment_even_if_the_cron_never_ran(): void
    {
        $this->comingSoon(true, now()->subMinute()->format('Y-m-d H:i:s'));

        // Flag deliberately left ON — no command has run.
        $this->assertSame('1', SystemSetting::getValue('coming_soon_mode'));
        $this->get('/')->assertOk();
    }

    /** With no launch time the toggle behaves exactly as it always did. */
    public function test_without_a_launch_time_the_toggle_still_rules(): void
    {
        $this->comingSoon(true);
        $this->get('/')->assertStatus(503);

        $this->comingSoon(false);
        $this->get('/')->assertOk();
    }

    /**
     * A malformed stored value must not take the site down NOR open it
     * early — it is treated as "no launch time set".
     */
    public function test_a_malformed_launch_time_is_ignored(): void
    {
        $this->comingSoon(true, 'not a date');

        $this->get('/')->assertStatus(503);
    }

    public function test_the_command_flips_the_flag_once_the_moment_passes(): void
    {
        $this->comingSoon(true, now()->subMinute()->format('Y-m-d H:i:s'));

        $this->artisan('site:launch')->assertSuccessful();

        SystemSetting::forgetCache();
        $this->assertSame('0', SystemSetting::getValue('coming_soon_mode'));
    }

    public function test_the_command_leaves_a_future_launch_alone(): void
    {
        $this->comingSoon(true, now()->addDays(2)->format('Y-m-d H:i:s'));

        $this->artisan('site:launch')->assertSuccessful();

        SystemSetting::forgetCache();
        $this->assertSame('1', SystemSetting::getValue('coming_soon_mode'), 'the site must stay hidden');
    }

    /** No launch time means the command must never open the site by itself. */
    public function test_the_command_does_nothing_without_a_launch_time(): void
    {
        $this->comingSoon(true);

        $this->artisan('site:launch')->assertSuccessful();

        SystemSetting::forgetCache();
        $this->assertSame('1', SystemSetting::getValue('coming_soon_mode'));
    }

    /**
     * The widget renders and saves. It is the only way the trust sets a
     * launch time, and it lives on the dashboard — a fatal here greets every
     * admin on login.
     */
    public function test_the_dashboard_widget_renders_and_saves_a_launch_time(): void
    {
        $this->comingSoon(true);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = AdminUser::create([
            'name' => 'Launch Admin', 'email' => 'launch@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh(), 'admin');

        Livewire::test(ComingSoonToggleWidget::class)
            ->assertOk()
            ->set('launchAt', '2026-08-15T09:00')
            ->call('saveLaunchAt')
            ->assertHasNoErrors();

        SystemSetting::forgetCache();
        $this->assertSame('2026-08-15 09:00:00', SystemSetting::getValue('launch_at'));
    }

    public function test_force_launches_immediately(): void
    {
        $this->comingSoon(true, now()->addYear()->format('Y-m-d H:i:s'));

        $this->artisan('site:launch', ['--force' => true])->assertSuccessful();

        SystemSetting::forgetCache();
        $this->assertSame('0', SystemSetting::getValue('coming_soon_mode'));
    }
}
