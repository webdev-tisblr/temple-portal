<?php

namespace Tests\Feature;

use App\Filament\Resources\HallResource\Pages\CreateHall;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section as FormSection;
use Filament\Infolists\Components\Section as InfolistSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every admin section collapses (2026-08-13).
 *
 * Set once in AppServiceProvider rather than on 137 Section::make() calls,
 * because a convention that has to be retyped on every new resource is one
 * that gets missed — which is how it ended up present on some pages and
 * absent on others in the first place.
 *
 * These cases pin the three behaviours that make the global default safe to
 * leave unattended.
 */
class AdminSectionsCollapsibleTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Layout Admin',
            'email' => 'layout@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    /** 1. A plain section is collapsible without asking. */
    public function test_a_headed_section_is_collapsible_by_default(): void
    {
        $this->assertTrue(FormSection::make('Booking Rules')->isCollapsible());
        $this->assertTrue(InfolistSection::make('Order Details')->isCollapsible());
    }

    /**
     * 2. A headingless section is NOT. Those three are plain layout
     *    wrappers with no header bar to hold a chevron — collapsing one
     *    would hide its fields behind an unlabelled arrow.
     */
    public function test_a_headingless_section_stays_fixed(): void
    {
        $this->assertFalse(FormSection::make()->isCollapsible());
    }

    /**
     * 3. Anything explicit still wins. configureUsing runs inside make(),
     *    before the chained calls, so a resource can still opt out — and a
     *    section that asks to start collapsed keeps doing so.
     */
    public function test_an_explicit_setting_overrides_the_global_default(): void
    {
        $this->assertFalse(FormSection::make('Fixed')->collapsible(false)->isCollapsible());

        $collapsed = FormSection::make('Reminders')->collapsed();
        $this->assertTrue($collapsed->isCollapsible());
        $this->assertTrue($collapsed->isCollapsed());
    }

    /** 4. Real resource forms still render with the default applied. */
    public function test_resource_forms_still_render(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(CreateHall::class)->assertOk();
        Livewire::test(CreateProduct::class)->assertOk();
    }
}
