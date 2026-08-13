<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
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

    /**
     * 5. Collapsed state persists, which is ONLY safe because each section
     *    carries a distinct id. Filament keys the Alpine store on
     *    `section-${$el.id}-isCollapsed`; with our sections previously
     *    setting no id at all, every one of them would have keyed on the
     *    same empty string and collapsing one would collapse the lot.
     */
    public function test_each_section_persists_under_its_own_id(): void
    {
        $rules = FormSection::make('Booking Rules');
        $pricing = FormSection::make('Pricing');

        $this->assertTrue($rules->shouldPersistCollapsed());
        $this->assertNotNull($rules->getId());
        $this->assertNotSame($rules->getId(), $pricing->getId(), 'two sections must never share a persist key');
    }

    /**
     * 6. The id is scoped to the PAGE, not just the heading — otherwise
     *    collapsing "Status" on Products would collapse "Status" on Sevas,
     *    which reads as the UI losing track of itself.
     */
    public function test_the_same_heading_on_two_pages_gets_two_ids(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin(), 'admin');

        // Deliberately NOT deduped — duplicates are what we are looking for.
        $idsOn = function (string $page): array {
            $html = Livewire::test($page)->html();
            preg_match_all('/id="(sec-[a-z0-9-]+)"/', $html, $m);

            return $m[1];
        };

        $hall = $idsOn(CreateHall::class);
        $product = $idsOn(CreateProduct::class);
        $settings = $idsOn(SystemSettings::class);

        $this->assertNotEmpty($hall, 'sections must render their ids into the DOM');
        $this->assertNotEmpty($product);

        $this->assertSame([], array_intersect($hall, $product), 'ids must not collide across pages');

        // Within a page, two sections sharing an id would share a persist
        // key and collapse together. Settings is the real test here: it is
        // by far the largest form, and its tabs make repeated headings
        // plausible in a way they are not on a resource form.
        foreach (['hall' => $hall, 'product' => $product, 'settings' => $settings] as $page => $ids) {
            $this->assertSame(
                array_values(array_unique($ids)),
                $ids,
                "two sections on the {$page} page share an id",
            );
        }
    }
}
