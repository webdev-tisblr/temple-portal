<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AppStringOverrideResource;
use App\Filament\Resources\DailyDarshanPhotoResource\Pages\ListDailyDarshanPhotos;
use App\Filament\Resources\DarshanCardTemplateResource;
use App\Filament\Resources\DonationResource\Pages\ListDonations;
use App\Filament\Resources\DonationResource\Pages\ViewDonation;
use App\Filament\Resources\GalleryResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings;
use App\Filament\Resources\SevaResource\Pages\EditSeva;
use App\Filament\Resources\SevaSlotPoolResource;
use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\OrderFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * RBAC regression coverage for the 2026-08-09 admin authorization audit.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A policy that is never consulted looks EXACTLY like one that is: the
 * permission row exists, the Shield UI renders its checkbox, an operator
 * ticks it — and nothing changes, in either direction. Filament's
 * `Filament\authorize()` helper returns `Response::allow()` when a model has
 * no policy class (vendor/filament/filament/src/helpers.php), so a missing
 * policy fails OPEN and is invisible from the outside.
 *
 * Every test below therefore asserts BOTH directions on the same surface:
 * an admin WITHOUT the permission is denied, and an admin WITH it is
 * allowed. A one-sided assertion would pass against the pre-fix code.
 *
 * Everything runs on the `admin` guard (the Filament panel's authGuard) and
 * against real permission rows produced by RolePermissionSeeder, so a typo
 * between a policy slug and the seeder fails the suite rather than shipping.
 *
 * MySQL-only project: needs the `temple_portal_test` database (phpunit.xml).
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Filament\authorize() resolves the user through
        // Filament::auth()->user(), which needs a current panel. Without
        // this, resource-level assertions below throw instead of answering.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Build a throwaway non-super-admin holding exactly the permissions
     * given (plus panel_user, without which nothing can enter the panel).
     *
     * A fresh role per call keeps tests independent — reusing a bundled
     * role would couple these assertions to the seeder's grant matrix,
     * which is a separate thing to test.
     *
     * @param  array<int,string>  $permissions
     */
    private function adminWith(array $permissions): AdminUser
    {
        $suffix = Str::lower(Str::random(8));

        $role = Role::create([
            'name' => "test_role_{$suffix}",
            'guard_name' => 'admin',
        ]);
        $role->syncPermissions(array_merge(['panel_user'], $permissions));

        $user = AdminUser::create([
            'name' => "Test Admin {$suffix}",
            'email' => "admin-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        // Spatie caches the permission map process-wide; a role created
        // mid-test is otherwise invisible to the next ->can() call.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function actAs(AdminUser $user): void
    {
        $this->actingAs($user, 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---------------------------------------------------------------
    // G1 / G2 / G3 — resources that had NO policy and so failed OPEN.
    // ---------------------------------------------------------------

    public function test_g1_app_string_override_resource_is_denied_without_permission(): void
    {
        // Pre-fix this returned TRUE for every panel user, because
        // AppStringOverrideResource had no policy at all — meaning any
        // volunteer could rewrite wording pushed to every installed phone.
        $this->actAs($this->adminWith([]));

        $this->assertFalse(AppStringOverrideResource::canViewAny());
        $this->assertFalse(AppStringOverrideResource::canCreate());
    }

    public function test_g1_app_string_override_resource_is_allowed_with_permission(): void
    {
        $this->actAs($this->adminWith([
            'view_any_app::string::override',
            'create_app::string::override',
        ]));

        $this->assertTrue(AppStringOverrideResource::canViewAny());
        $this->assertTrue(AppStringOverrideResource::canCreate());
    }

    public function test_g2_darshan_card_template_resource_is_denied_without_permission(): void
    {
        $this->actAs($this->adminWith([]));

        $this->assertFalse(DarshanCardTemplateResource::canViewAny());
        $this->assertFalse(DarshanCardTemplateResource::canCreate());
    }

    public function test_g2_darshan_card_template_resource_is_allowed_with_permission(): void
    {
        $this->actAs($this->adminWith([
            'view_any_darshan::card::template',
            'create_darshan::card::template',
        ]));

        $this->assertTrue(DarshanCardTemplateResource::canViewAny());
        $this->assertTrue(DarshanCardTemplateResource::canCreate());
    }

    public function test_g3_seva_slot_pool_resource_is_denied_without_permission(): void
    {
        $this->actAs($this->adminWith([]));

        $this->assertFalse(SevaSlotPoolResource::canViewAny());
        $this->assertFalse(SevaSlotPoolResource::canCreate());
    }

    public function test_g3_seva_slot_pool_resource_is_allowed_with_permission(): void
    {
        $this->actAs($this->adminWith([
            'view_any_seva::slot::pool',
            'create_seva::slot::pool',
        ]));

        $this->assertTrue(SevaSlotPoolResource::canViewAny());
        $this->assertTrue(SevaSlotPoolResource::canCreate());
    }

    // ---------------------------------------------------------------
    // G8 — gallery slug reconciled onto `gallery::image`.
    // ---------------------------------------------------------------

    public function test_g8_gallery_permissions_use_the_shield_derived_slug(): void
    {
        // The Shield UI derives its checkbox names from the model basename
        // (GalleryImage → gallery::image). If the policy and the seeder ever
        // drift apart again, the checkboxes silently grant nothing.
        $this->assertDatabaseHas('permissions', [
            'name' => 'view_any_gallery::image',
            'guard_name' => 'admin',
        ]);
        $this->assertDatabaseMissing('permissions', [
            'name' => 'view_any_gallery',
            'guard_name' => 'admin',
        ]);

        $this->actAs($this->adminWith([]));
        $this->assertFalse(GalleryResource::canViewAny());

        $this->actAs($this->adminWith(['view_any_gallery::image']));
        $this->assertTrue(GalleryResource::canViewAny());
    }

    public function test_g8_bulk_upload_uses_the_reconciled_gallery_slug(): void
    {
        // ListGalleryImages::bulkUpload checked `create_gallery`. Renaming
        // the slug without fixing it would have flipped this from fail-open
        // to fail-CLOSED — invisible to everyone but super_admin.
        $this->actAs($this->adminWith(['view_any_gallery::image']));
        Livewire::test(GalleryResource\Pages\ListGalleryImages::class)
            ->assertActionHidden('bulkUpload');

        $this->actAs($this->adminWith(['view_any_gallery::image', 'create_gallery::image']));
        Livewire::test(GalleryResource\Pages\ListGalleryImages::class)
            ->assertActionVisible('bulkUpload');
    }

    // ---------------------------------------------------------------
    // G10 — money / PII actions that had zero authorization.
    // ---------------------------------------------------------------

    public function test_g10_donation_export_requires_export_donations(): void
    {
        // Full donor name + phone + amount + FY as CSV/PDF.
        $this->actAs($this->adminWith(['view_any_donation']));
        Livewire::test(ListDonations::class)->assertActionHidden('export');

        $this->actAs($this->adminWith(['view_any_donation', 'export_donations']));
        Livewire::test(ListDonations::class)->assertActionVisible('export');
    }

    public function test_g10_generate_80g_receipt_requires_regenerate_80g_receipt(): void
    {
        // The donor needs a PAN: since the strict 80G rule landed
        // (2026-08-09) the action's visible() closure ANDs the permission
        // with 80G eligibility, so a PAN-less donation would stay hidden for
        // the *record's* sake and mask whether the permission works at all.
        $donation = DonationFactory::new()->create([
            'receipt_generated' => false,
            'devotee_id' => DevoteeFactory::new()->withPan()->create()->id,
        ]);

        $this->actAs($this->adminWith(['view_any_donation', 'view_donation']));
        Livewire::test(ViewDonation::class, ['record' => $donation->getKey()])
            ->assertActionHidden('generate_receipt');

        $this->actAs($this->adminWith([
            'view_any_donation', 'view_donation', 'regenerate_80g_receipt',
        ]));
        Livewire::test(ViewDonation::class, ['record' => $donation->getKey()])
            ->assertActionVisible('generate_receipt');
    }

    public function test_g10_generate_80g_receipt_still_respects_record_state(): void
    {
        // The permission AND the state live in one closure; make sure the
        // permission did not accidentally override the "already generated"
        // guard (calling ->visible() twice would have replaced it).
        $donation = DonationFactory::new()->create(['receipt_generated' => true]);

        $this->actAs($this->adminWith([
            'view_any_donation', 'view_donation', 'regenerate_80g_receipt',
        ]));
        Livewire::test(ViewDonation::class, ['record' => $donation->getKey()])
            ->assertActionHidden('generate_receipt');
    }

    public function test_g10_order_stock_restoring_actions_require_approve_refund(): void
    {
        $order = OrderFactory::new()->create(['status' => 'confirmed']);

        $this->actAs($this->adminWith(['view_any_order', 'view_order', 'update_order']));
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('update_status')
            ->assertActionHidden('cancel_order');

        $this->actAs($this->adminWith([
            'view_any_order', 'view_order', 'update_order', 'approve_refund',
        ]));
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('update_status')
            ->assertActionVisible('cancel_order');
    }

    public function test_g10_cancel_order_still_respects_record_state(): void
    {
        // An already-cancelled order must stay un-cancellable even for a
        // holder of approve_refund — proof the state half of the merged
        // visible() closure survived.
        $order = OrderFactory::new()->create(['status' => 'cancelled']);

        $this->actAs($this->adminWith([
            'view_any_order', 'view_order', 'update_order', 'approve_refund',
        ]));
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('cancel_order');
    }

    // ---------------------------------------------------------------
    // G11 — content permissions must not escalate into system config.
    // ---------------------------------------------------------------

    public function test_g11_live_darshan_settings_requires_page_system_settings(): void
    {
        // `staff` and `volunteer` hold view_any_daily::darshan::photo. This
        // action writes temple_system_settings rows and uploads to R2, so
        // holding the content permission alone must not be enough.
        $this->actAs($this->adminWith(['view_any_daily::darshan::photo']));
        Livewire::test(ListDailyDarshanPhotos::class)->assertActionHidden('live_settings');

        $this->actAs($this->adminWith(['view_any_daily::darshan::photo', 'page_SystemSettings']));
        Livewire::test(ListDailyDarshanPhotos::class)->assertActionVisible('live_settings');
    }

    // ---------------------------------------------------------------
    // G12 — outbound-message actions require send_announcement.
    // ---------------------------------------------------------------

    public function test_g12_send_test_requires_send_announcement(): void
    {
        // "Send test" delivers a REAL email/WhatsApp/SMS to an arbitrary
        // operator-supplied recipient — it spends live BSP credit.
        $template = NotificationTemplate::create([
            'key' => 'donation.confirmed',
            'label' => 'Test template',
            'channel' => 'email',
            'is_enabled' => true,
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        $this->actAs($this->adminWith([
            'view_any_notification::template', 'view_notification::template', 'update_notification::template',
        ]));
        Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->assertActionHidden('send_test');

        $this->actAs($this->adminWith([
            'view_any_notification::template', 'view_notification::template',
            'update_notification::template', 'send_announcement',
        ]));
        Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->assertActionVisible('send_test');
    }

    // ---------------------------------------------------------------
    // G17 — custom bulk actions are NOT auto-authorized by Filament.
    // ---------------------------------------------------------------

    public function test_g17_mark_completed_bulk_action_requires_update_seva_booking(): void
    {
        SevaBookingFactory::new()->create();

        // pujari / volunteer are view-only by design but would still have
        // seen (and been able to run) this status mutation.
        $this->actAs($this->adminWith(['view_any_seva::booking', 'view_seva::booking']));
        Livewire::test(ListSevaBookings::class)->assertTableBulkActionHidden('mark_completed');

        $this->actAs($this->adminWith([
            'view_any_seva::booking', 'view_seva::booking', 'update_seva::booking',
        ]));
        Livewire::test(ListSevaBookings::class)->assertTableBulkActionVisible('mark_completed');
    }

    // ---------------------------------------------------------------
    // G15 — low-blast-radius bulk actions, same mechanism as G17.
    // ---------------------------------------------------------------

    public function test_g15_gallery_bulk_actions_require_update_gallery_image(): void
    {
        $this->actAs($this->adminWith(['view_any_gallery::image']));
        Livewire::test(GalleryResource\Pages\ListGalleryImages::class)
            ->assertTableBulkActionHidden('setCategory')
            ->assertTableBulkActionHidden('setWallpaper');

        $this->actAs($this->adminWith(['view_any_gallery::image', 'update_gallery::image']));
        Livewire::test(GalleryResource\Pages\ListGalleryImages::class)
            ->assertTableBulkActionVisible('setCategory')
            ->assertTableBulkActionVisible('setWallpaper');
    }

    // ---------------------------------------------------------------
    // G7 — seva::reminder::rule was 12 seeded permissions with no check.
    // ---------------------------------------------------------------

    public function test_g7_reminder_rules_repeater_requires_its_own_permission(): void
    {
        $seva = SevaFactory::new()->create();

        $this->actAs($this->adminWith(['view_any_seva', 'view_seva', 'update_seva']));
        Livewire::test(EditSeva::class, ['record' => $seva->getKey()])
            ->assertFormFieldIsHidden('reminderRules');

        $this->actAs($this->adminWith([
            'view_any_seva', 'view_seva', 'update_seva', 'update_seva::reminder::rule',
        ]));
        Livewire::test(EditSeva::class, ['record' => $seva->getKey()])
            ->assertFormFieldExists('reminderRules');
    }

    // ---------------------------------------------------------------
    // G4 / G5 — dashboard widget permissions.
    // ---------------------------------------------------------------

    public function test_g4_every_widget_class_has_a_matching_permission(): void
    {
        // Widget canView() is fail-CLOSED, so a widget class with no
        // permission row is invisible to every non-super-admin. Derive the
        // list from disk so a newly added widget fails this test loudly
        // instead of quietly vanishing from the dashboard.
        $classes = collect(glob(app_path('Filament/Widgets/*.php')))
            ->map(fn (string $path): string => basename($path, '.php'));

        $this->assertGreaterThanOrEqual(8, $classes->count(), 'Widget discovery found nothing — check the glob path.');

        foreach ($classes as $class) {
            // Not assertDatabaseHas(): its third argument is a CONNECTION
            // name, not a failure message.
            $this->assertTrue(
                Permission::where('name', "widget_{$class}")->where('guard_name', 'admin')->exists(),
                "Widget {$class} has no widget_{$class} permission — canView() is fail-closed, so it would be invisible to everyone but super_admin.",
            );
        }
    }

    public function test_g5_and_g9_obsolete_permissions_are_gone_with_no_orphan_pivot_rows(): void
    {
        $obsolete = [
            'widget_RecentDonationsTable',   // G5 — widget class deleted
            'widget_SevaBookingOverview',    // G5 — widget class deleted
            'export_orders',                 // G9 — no order export exists
            'assign_seva_to_pujari',         // G9 — never checked anywhere
            'view_any_hero::slide',          // G6 — resource deleted
            'view_any_gallery',              // G8 — superseded by gallery::image
        ];

        foreach ($obsolete as $name) {
            $this->assertDatabaseMissing('permissions', [
                'name' => $name,
                'guard_name' => 'admin',
            ]);
        }

        // Nothing may reference a permission id that no longer exists.
        $orphans = DB::table('role_has_permissions')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereNull('permissions.id')
            ->count();

        $this->assertSame(0, $orphans, 'role_has_permissions holds rows pointing at deleted permissions.');
    }

    // ---------------------------------------------------------------
    // G6 — the seeder must stop warning about unknown permissions.
    // ---------------------------------------------------------------

    public function test_g6_every_granted_permission_name_actually_exists(): void
    {
        // The `hero::slide` grant printed "unknown permission … skipped" on
        // every production deploy, which trains operators to ignore seeder
        // warnings. Assert the whole matrix resolves.
        $granted = DB::table('role_has_permissions')->distinct()->pluck('permission_id');
        $existing = Permission::whereIn('id', $granted)->count();

        $this->assertSame($granted->count(), $existing);
    }

    // ---------------------------------------------------------------
    // G14 — the ungated filesystem-mutating route is gone.
    // ---------------------------------------------------------------

    public function test_g14_storage_repair_route_no_longer_exists(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('admin.storage-repair'),
            'The obsolete /admin-tools/storage-repair route is back — it mutates the filesystem behind only auth("admin")->check().',
        );

        $this->get('/admin-tools/storage-repair')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Super admin must still bypass everything (Gate::before).
    // ---------------------------------------------------------------

    public function test_super_admin_still_bypasses_every_new_gate(): void
    {
        $suffix = Str::lower(Str::random(8));
        $super = AdminUser::create([
            'name' => 'Super',
            'email' => "super-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');
        $this->actAs($super);

        // The new policies must not have fenced the super admin out.
        $this->assertTrue(AppStringOverrideResource::canViewAny());
        $this->assertTrue(DarshanCardTemplateResource::canViewAny());
        $this->assertTrue(SevaSlotPoolResource::canViewAny());
        $this->assertTrue(GalleryResource::canViewAny());

        // And the newly gated actions must still be reachable.
        Livewire::test(ListDonations::class)->assertActionVisible('export');
    }

    public function test_super_admin_bypass_does_not_leak_to_other_guards(): void
    {
        // Gate::before is deliberately scoped to AdminUser instances so a
        // devotee or legacy web User holding a stray role cannot escalate.
        //
        // Probed with an ability that has no gate and no policy: for a
        // super_admin AdminUser the bypass answers true, for anyone else the
        // callback returns null and Laravel's default denial stands. Using
        // an unregistered ability keeps this a test of the BYPASS rather
        // than of any one policy (the policies type-hint AdminUser, so
        // handing them a Devotee would TypeError before proving anything).
        $ability = 'an_ability_no_gate_or_policy_defines';

        $devotee = DevoteeFactory::new()->create();
        $this->assertFalse(Gate::forUser($devotee)->allows($ability));

        $suffix = Str::lower(Str::random(8));
        $super = AdminUser::create([
            'name' => 'Super Scope',
            'email' => "super-scope-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');
        $this->assertTrue(Gate::forUser($super->fresh())->allows($ability));

        // A non-super AdminUser must NOT get the bypass either.
        $this->assertFalse(Gate::forUser($this->adminWith([]))->allows($ability));
    }

    // ---------------------------------------------------------------
    // A deactivated admin must never reach the panel at all.
    // ---------------------------------------------------------------

    public function test_deactivated_admin_cannot_access_the_panel(): void
    {
        $user = $this->adminWith(['view_any_app::string::override']);
        $user->update(['is_active' => false]);

        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }
}
