<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\AdminUser;
use App\Models\SystemSetting;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Smoke test for the System Settings page.
 *
 * This page is edited more often than anything else in the admin and is one
 * enormous form definition — a malformed component, a closure with an
 * unresolvable type-hint, a Fieldset nested somewhere Filament will not take
 * it, and the whole page 500s. There is no partial failure: the trust loses
 * access to every setting at once, including the ones that turn features off
 * in an emergency.
 *
 * Rendering it in a test is the cheapest possible guard against that.
 */
class SystemSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Settings Admin',
            'email' => 'settings@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    public function test_the_settings_page_renders(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(SystemSettings::class)->assertOk();
    }

    /**
     * Hall and store GST live in one merged section (2026-08-13). Both sets
     * of keys must still hydrate and save — a Fieldset that Filament does
     * not dehydrate would silently stop persisting the switch that decides
     * whether devotees are taxed.
     */
    public function test_the_merged_gst_section_saves_both_hall_and_store_keys(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(SystemSettings::class)
            // Required elsewhere on the form; a fresh test DB has no
            // settings rows at all, so save() would fail validation on it.
            ->set('data.trust_name', 'Shree Patadiya Hanumanji Seva Trust')
            ->set('data.trust_gstin', '24AAKTS1478C1ZX')
            ->set('data.hall_gst_enabled', true)
            ->set('data.hall_gst_rate', '18.00')
            ->set('data.store_gst_enabled', true)
            ->set('data.store_gst_rate', '5.00')
            ->call('save')
            ->assertHasNoErrors();

        SystemSetting::forgetCache();

        $this->assertSame('24AAKTS1478C1ZX', SystemSetting::getValue('trust_gstin'));
        // '1'/'0' strings, not booleans — every reader compares === '1'.
        $this->assertSame('1', SystemSetting::getValue('hall_gst_enabled'));
        $this->assertSame('18.00', SystemSetting::getValue('hall_gst_rate'));
        $this->assertSame('1', SystemSetting::getValue('store_gst_enabled'));
        $this->assertSame('5.00', SystemSetting::getValue('store_gst_rate'));
    }
}
