<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the full Filament Shield permission inventory and the bundled role
 * presets (super_admin, trustee, accountant, staff, volunteer, pujari).
 *
 * Idempotent. Safe to re-run on production: every permission and role uses
 * firstOrCreate, and role → permission links are SYNCED (not added), so
 * removing a permission from a preset here will revoke it from existing
 * role rows on the next run. New roles created at runtime from the
 * Filament UI are left untouched.
 *
 * Permission naming convention (Filament Shield default — see the policy
 * classes under app/Policies/):
 *   • Resources:  view_any_<model>, view_<model>, create_<model>,
 *                 update_<model>, delete_<model>, delete_any_<model>,
 *                 force_delete_<model>, force_delete_any_<model>,
 *                 restore_<model>, restore_any_<model>,
 *                 replicate_<model>, reorder_<model>
 *                 (model = StudlyCase → snake with `::` between words,
 *                  e.g. AdminUser → `admin::user`, SevaBooking → `seva::booking`)
 *   • Pages:      page_<ClassName>
 *   • Widgets:    widget_<ClassName>
 *   • Custom:     hand-listed below
 *
 * Super admin gets every permission AND additionally bypasses every Gate
 * via Gate::before in AuthServiceProvider — so even if a role row has no
 * permissions, a super_admin user can still operate.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Filament Shield identifies resources by the model class basename
     * converted to a `::`-delimited snake-case slug. This map keeps the
     * code grep-able: change a permission slug here, change it everywhere.
     */
    private const RESOURCE_SLUGS = [
        'admin::user',
        // G1 (2026-08-09): AppStringOverrideResource had no slug AND no
        // policy, so Filament failed open on live app-wording overrides.
        'app::string::override',
        'contact::submission',
        'daily::darshan::photo',
        'devotee',
        'donation',
        'donation::campaign',
        'donation::type',
        'event',
        // G8 (2026-08-09): was the bare `gallery` slug, which diverged from
        // the `gallery::image` set shield:generate derives from the
        // GalleryImage model — the Shield UI checkboxes granted nothing.
        // GalleryImagePolicy now checks `gallery::image` too.
        'gallery::image',
        'gallery::category',
        'guide',
        'guide::category',
        'hall',
        'hall::booking',
        'hall::reminder::rule',
        'notification',
        'notification::log',
        'notification::template',
        'order',
        'page',
        'product',
        'product::category',
        'role',
        'seva',
        'seva::booking',
        'seva::category',
        'seva::reminder::rule',
        'seva::slot::pool',
        'darshan::card::template',
        'status::template',
        'trustee',
    ];

    private const RESOURCE_ACTIONS = [
        'view_any', 'view', 'create', 'update',
        'delete', 'delete_any',
        'force_delete', 'force_delete_any',
        'restore', 'restore_any',
        'replicate', 'reorder',
    ];

    private const PAGES = [
        // Item 6.1 — the "who is allowed to take cash" switch. One page,
        // one permission, four record types. CounterEntryPage::canAccess()
        // checks it (fail-closed, like every other page here), and the page
        // ALSO checks the per-type create_* permission before writing, so
        // an admin can be allowed to take seva cash without being allowed
        // to take donations (which touch 80G receipt numbering).
        'CounterEntryPage',
        'DarshanTimingsPage',
        'HomePageSettingsPage',
        'FinancialReports',
        'SystemSettings',
    ];

    /**
     * MUST match the class names under app/Filament/Widgets/ exactly.
     *
     * Widget canView() is fail-CLOSED (`->can("widget_X")`), so a widget
     * missing from this list is invisible to every non-super-admin; and a
     * widget listed here that no longer exists is a dead grant nobody can
     * ever use. G4 (four widgets missing) and G5 (two deleted widgets still
     * granted) were both live on 2026-08-09.
     */
    private const WIDGETS = [
        'ActiveContentOverview',      // G4 — was missing
        'BookingRevenueOverview',
        'ComingSoonToggleWidget',
        'DonationChart',
        'DonationStatsOverview',
        'EngagementOverview',         // G4 — was missing
        'OperationsTodayOverview',    // G4 — was missing
        'QueueHealthOverview',
        'SevaBookingsChart',          // G4 — was missing
        // G5: 'RecentDonationsTable' and 'SevaBookingOverview' removed —
        // neither widget class exists any more. See OBSOLETE_PERMISSIONS.
    ];

    /**
     * Permissions that must be actively REMOVED from the database, not just
     * left out of the matrices above.
     *
     * syncPermissions() already revokes them from the bundled roles, but the
     * rows themselves would linger in the Shield UI (and on any role created
     * at runtime) forever. pruneObsoletePermissions() deletes both the
     * permission rows and their pivot entries so nothing is orphaned.
     */
    private const OBSOLETE_PERMISSIONS = [
        // G5 — widget classes deleted.
        'widget_RecentDonationsTable',
        'widget_SevaBookingOverview',

        // G9 — decorative custom permissions with no feature to gate.
        // `export_orders`: OrderResource has no export action at all.
        // `assign_seva_to_pujari`: assignment is the `assignee_id` field on
        // SevaResource, now gated on update_seva — a second permission for
        // the same field was never checked and never will be.
        'export_orders',
        'assign_seva_to_pujari',
    ];

    /**
     * Slug prefixes whose full 12-action permission set is obsolete.
     *
     * G6 — `hero::slide`: HeroSlideResource was deleted (replaced by the
     *      Home Page Settings page). The seeder used to grant it to trustee
     *      without ever creating it, which printed an "unknown permission"
     *      warning on EVERY production deploy and trained operators to
     *      ignore seeder warnings.
     * G8 — `gallery`: superseded by `gallery::image` (see RESOURCE_SLUGS).
     */
    private const OBSOLETE_RESOURCE_SLUGS = [
        'hero::slide',
        'gallery',
    ];

    /**
     * Cross-cutting permissions that aren't tied to a single Filament resource.
     * Add new action-level permissions here as the app grows.
     *
     * ⚠️ EVERY entry below is checked somewhere in app/ — the call site is
     * named in the comment. G9 (2026-08-09) found 6 of 8 were decorative:
     * seeded, rendered as checkboxes in the Shield UI, and never consulted.
     * If you add one here without a `->visible(fn () => …can('x'))` or an
     * `abort_unless`, you are shipping a lie. Two were deleted outright —
     * see OBSOLETE_PERMISSIONS.
     */
    private const CUSTOM_PERMISSIONS = [
        // Required by AdminUser::canAccessPanel — without this, even an
        // is_active admin with roles cannot enter /admin.
        'panel_user',

        // Financial actions surfaced as buttons in Filament resources.
        // approve_refund       → ViewOrder::cancel_order + ViewOrder::update_status
        //                        (both restore product stock).
        // regenerate_80g_receipt → ViewDonation::generate_receipt (mints an
        //                        80G receipt number).
        // export_donations     → ListDonations::export (full donor CSV/PDF).
        'approve_refund',
        'regenerate_80g_receipt',
        'export_donations',

        // Communication actions.
        // resend_notification  → NotificationLogResource::resend.
        // send_announcement    → NotificationResource::send_now,
        //                        EditNotification::send_now,
        //                        EditNotificationTemplate::send_test,
        //                        EditDailyDarshanPhoto::send_booking_day_notifications.
        'resend_notification',
        'send_announcement',

        // manage_sms_webhook → SystemSettings::canManageSmsWebhook(), which
        //   gates the "Delivery reports (webhook)" fieldset on the SMS tab
        //   and is re-checked with abort_unless inside
        //   SystemSettings::regenerateMsg91WebhookToken().
        // Separate from page_SystemSettings because what it guards is a live
        // credential: the token in the webhook URL is the only thing
        // protecting that endpoint, and regenerating it silently stops all
        // SMS delivery reporting until someone re-pastes the URL into MSG91.
        'manage_sms_webhook',
    ];

    public function run(): void
    {
        // Clear Spatie's permission cache before AND after seeding so any
        // request that races this seeder doesn't see a half-built matrix.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::transaction(function (): void {
            $permissions = $this->ensurePermissions();
            $rolePermissions = $this->buildRolePermissionMatrix($permissions);
            $this->syncRoles($rolePermissions, $permissions);
            // Runs AFTER syncRoles so the bundled roles have already been
            // rewritten; this only has to clean up runtime-created roles and
            // the permission rows themselves.
            $this->pruneObsoletePermissions();
            $this->ensureDefaultSuperAdmin();
            $this->migrateLegacyDefaultAdmin();
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Create (or look up) every permission used by the app.
     *
     * @return array<string,Permission> map of name => Permission
     */
    private function ensurePermissions(): array
    {
        $names = [];

        foreach (self::RESOURCE_SLUGS as $slug) {
            foreach (self::RESOURCE_ACTIONS as $action) {
                $names[] = "{$action}_{$slug}";
            }
        }

        foreach (self::PAGES as $page) {
            $names[] = "page_{$page}";
        }

        foreach (self::WIDGETS as $widget) {
            $names[] = "widget_{$widget}";
        }

        foreach (self::CUSTOM_PERMISSIONS as $custom) {
            $names[] = $custom;
        }

        $map = [];
        foreach ($names as $name) {
            $map[$name] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'admin',
            ]);
        }

        return $map;
    }

    /**
     * Delete permission rows this seeder no longer owns, along with every
     * pivot row that references them, so nothing is left orphaned.
     *
     * Idempotent by construction: a DELETE over a name whitelist is a no-op
     * once the rows are gone. Deliberately narrow — it only ever touches the
     * exact names listed in OBSOLETE_PERMISSIONS / OBSOLETE_RESOURCE_SLUGS,
     * never a LIKE prefix (which would have eaten `gallery::image` and
     * `gallery::category` alongside the obsolete `gallery` set).
     */
    private function pruneObsoletePermissions(): void
    {
        $names = self::OBSOLETE_PERMISSIONS;

        foreach (self::OBSOLETE_RESOURCE_SLUGS as $slug) {
            foreach (self::RESOURCE_ACTIONS as $action) {
                $names[] = "{$action}_{$slug}";
            }
        }

        $ids = Permission::whereIn('name', $names)
            ->where('guard_name', 'admin')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Pivot cleanup first. The Spatie tables have ON DELETE CASCADE in a
        // stock install, but this seeder must not depend on the FK having
        // survived every migration this project has been through, and a
        // dangling role_has_permissions row is a phantom grant.
        //
        // NOTE the `?:` rather than a config() default: this project's
        // published config/permission.php declares
        // `column_names.permission_pivot_key => null`, so config() finds the
        // key and returns null — the second argument to config() never fires
        // and you get `where '' in (…)` / SQLSTATE[42S22].
        $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';

        DB::table(config('permission.table_names.role_has_permissions') ?: 'role_has_permissions')
            ->whereIn($pivotKey, $ids)
            ->delete();

        DB::table(config('permission.table_names.model_has_permissions') ?: 'model_has_permissions')
            ->whereIn($pivotKey, $ids)
            ->delete();

        Permission::whereIn('id', $ids)->delete();

        $this->command?->info('RolePermissionSeeder: pruned '.$ids->count().' obsolete permission(s).');
    }

    /**
     * Map role name → list of permission names it should hold.
     *
     * `super_admin` gets everything (also bypassed via Gate::before, but we
     * still grant explicit permissions so the Shield UI shows the right state).
     *
     * @param  array<string,Permission>  $allPermissions
     * @return array<string,array<int,string>>
     */
    private function buildRolePermissionMatrix(array $allPermissions): array
    {
        $allNames = array_keys($allPermissions);

        // Helpers for readable permission selection.
        $crud = fn (string $slug, array $actions = ['view_any', 'view', 'create', 'update']) => array_map(fn ($a) => "{$a}_{$slug}", $actions);

        $readOnly = fn (string $slug) => $crud($slug, ['view_any', 'view']);

        return [
            // ---- Super Admin: everything. -------------------------------
            'super_admin' => $allNames,

            // ---- Trustee: full operational + financial, no destructive
            //      admin ops (cannot manage other admins / roles).
            'trustee' => array_merge(
                ['panel_user'],
                // Content management — create/update across the site
                $crud('daily::darshan::photo'),
                $crud('event'),
                $crud('gallery::image'),
                $crud('gallery::category'),
                $crud('guide'),
                $crud('guide::category'),
                $crud('page'),

                // Catalog
                $crud('seva'),
                $crud('seva::category'),
                $crud('seva::slot::pool'),
                $crud('darshan::card::template'),
                $crud('hall'),
                $crud('product'),
                $crud('product::category'),
                $crud('donation::type'),
                $crud('donation::campaign'),

                // Transactional — view + update bookings/orders; donations
                // stay create+update (delete intentionally omitted).
                //
                // Item 6.1: the four `create_*` grants below are what let a
                // trustee take cash for that record type on the Counter
                // Entry page. They do NOT re-open the Filament resources —
                // all four still hard-return canCreate() === false.
                $crud('seva::booking', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                $crud('hall::booking', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                // delete/delete_any added 2026-08-13 so the trust can clear
                // orders from the admin (matching hall::booking) rather than
                // needing an SSH session. Deleting an order does NOT delete
                // its payment — the money record survives for audit.
                $crud('order', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                $crud('donation', ['view_any', 'view', 'create', 'update']),

                // People
                $crud('devotee', ['view_any', 'view', 'update']),
                $readOnly('admin::user'),

                // Communication
                $crud('notification::template'),
                // G6: $crud('hero::slide') removed — HeroSlideResource was
                // deleted, the slug was never in RESOURCE_SLUGS, and this
                // line printed 4 "unknown permission" warnings per deploy.
                $crud('status::template'),
                $crud('seva::reminder::rule'),
                $crud('hall::reminder::rule'),
                $crud('notification', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                $readOnly('notification::log'),
                $readOnly('contact::submission'),
                $crud('trustee'),

                // Pages, widgets, actions
                [
                    'page_FinancialReports',
                    'page_SystemSettings',
                    'page_DarshanTimingsPage',
                    'page_HomePageSettingsPage',
                    // Item 6.1 — may take cash at the counter for all four
                    // record types (create_donation above is what unlocks
                    // the 80G-bearing one).
                    'page_CounterEntryPage',
                ],
                array_map(fn (string $w) => "widget_{$w}", self::WIDGETS),
                // G1: app text overrides ship to every installed phone, so
                // trustee is the ONLY bundled role that gets them.
                $crud('app::string::override', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                [
                    'approve_refund',
                    'regenerate_80g_receipt',
                    'export_donations',
                    'resend_notification',
                    'send_announcement',
                    // Trustee already holds page_SystemSettings, so they are
                    // the role that would be pasting the webhook URL into
                    // MSG91 when SMS delivery reporting is set up.
                    'manage_sms_webhook',
                ],
            ),

            // ---- Accountant: financial focus. --------------------------
            'accountant' => array_merge(
                ['panel_user'],
                $readOnly('donation'),
                $crud('donation', ['update']), // notes/adjustments only
                $readOnly('donation::campaign'),
                $readOnly('donation::type'),
                $readOnly('order'),
                $readOnly('hall::booking'),
                $readOnly('seva::booking'),
                $readOnly('devotee'),
                $readOnly('notification'),
                ['page_FinancialReports'],
                [
                    'widget_DonationChart',
                    'widget_DonationStatsOverview',
                    // Seva + hall money. The accountant already holds
                    // read-only on both booking resources, so the tiles
                    // link somewhere they can actually go.
                    'widget_BookingRevenueOverview',
                    // G5: widget_RecentDonationsTable removed (class gone).
                    // G4: OperationsTodayOverview is the money-shaped
                    // replacement and was previously invisible to everyone.
                    'widget_OperationsTodayOverview',
                ],
                [
                    'approve_refund',
                    'regenerate_80g_receipt',
                    'export_donations',
                    // G9: export_orders deleted — no order export exists.
                ],
            ),

            // ---- Staff: front-desk operations. -------------------------
            'staff' => array_merge(
                ['panel_user'],
                $crud('devotee'),
                $crud('seva::booking', ['view_any', 'view', 'create', 'update']),
                $crud('hall::booking', ['view_any', 'view', 'create', 'update']),
                // Item 6.1: create_order is what lets the front desk ring
                // up a counter prasad sale on the Counter Entry page.
                $crud('order', ['view_any', 'view', 'create', 'update']),
                $crud('contact::submission'),
                $readOnly('seva'),
                $readOnly('hall'),
                $readOnly('product'),
                $readOnly('event'),
                $readOnly('gallery::image'),
                $readOnly('guide'),
                $readOnly('daily::darshan::photo'),
                $readOnly('donation'),
                $readOnly('donation::campaign'),
                [
                    'widget_ComingSoonToggleWidget',
                    // G4/G5: the two widgets staff used to be granted no
                    // longer exist. These two are the front-desk equivalents
                    // and were unreachable by anyone but super_admin.
                    'widget_OperationsTodayOverview',
                    'widget_SevaBookingsChart',
                ],
                // Item 6.1 — the front desk IS the counter, so staff take
                // cash for seva / hall / store. Deliberately NOT
                // create_donation: staff stay read-only on donations (they
                // already are), so the 80G-receipt-numbering record type
                // is not offered to them. The record-type switcher hides
                // what the permission does not allow.
                ['page_CounterEntryPage'],
                ['resend_notification'],
            ),

            // ---- Volunteer: light read-only. ---------------------------
            'volunteer' => array_merge(
                ['panel_user'],
                $readOnly('devotee'),
                $readOnly('seva::booking'),
                $readOnly('event'),
                $readOnly('gallery::image'),
                $readOnly('guide'),
                $readOnly('daily::darshan::photo'),
            ),

            // ---- Pujari: seva bookings only. ---------------------------
            // Replaces the old HiddenFromPujari trait. By giving the role
            // only seva::booking permissions, every other resource's
            // policy will deny viewAny → Filament hides it from navigation
            // and rejects direct URL access.
            'pujari' => array_merge(
                ['panel_user'],
                // View-only on seva bookings. Matches the original
                // HiddenFromPujari behaviour: priests browse upcoming sevas
                // but can't mutate booking state. Super admin can grant
                // `update_seva::booking` later from the Filament Shield
                // UI if a temple wants pujaris to mark sevas completed.
                $readOnly('seva::booking'),
                $readOnly('seva'),
                // G5: widget_SevaBookingOverview no longer exists; the chart
                // is the surviving seva-shaped widget.
                ['widget_SevaBookingsChart'],
            ),
        ];
    }

    /**
     * Sync permissions onto roles. Uses syncPermissions, not give, so the
     * seeder is authoritative for the bundled roles — drift gets corrected
     * on every run.
     *
     * @param  array<string,array<int,string>>  $rolePermissions
     * @param  array<string,Permission>  $allPermissions
     */
    private function syncRoles(array $rolePermissions, array $allPermissions): void
    {
        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'admin',
            ]);

            // Map names → Permission models; warn on typos so a misspelt
            // permission in this seeder doesn't silently disappear.
            $models = [];
            foreach (array_unique($permissionNames) as $name) {
                if (! isset($allPermissions[$name])) {
                    $this->command?->warn("RolePermissionSeeder: unknown permission '{$name}' for role '{$roleName}' — skipped");

                    continue;
                }
                $models[] = $allPermissions[$name];
            }

            $role->syncPermissions($models);
        }
    }

    /**
     * Ensure exactly one bootstrap super_admin exists so the platform can
     * always be administered after a fresh deploy. Email/password are
     * overridable via env so production never carries the dev default.
     */
    private function ensureDefaultSuperAdmin(): void
    {
        $email = env('SEEDER_ADMIN_EMAIL', 'admin@patadiyahanumanji.com');
        $password = env('SEEDER_ADMIN_PASSWORD', 'admin123456');

        $admin = AdminUser::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('SEEDER_ADMIN_NAME', 'Super Admin'),
                'password' => bcrypt($password),
                'is_active' => true,
            ]
        );

        // assignRole is idempotent — safe to call on every seed.
        $admin->assignRole('super_admin');
    }

    /**
     * Older deploys created the default admin at admin@templeportal.in
     * before the domain switched to patadiyahanumanji.com (see the
     * production-domain memory). If that legacy user still exists, just
     * make sure it has super_admin so the operator isn't locked out — DO
     * NOT delete or rename it (production may still use that email to
     * log in).
     */
    private function migrateLegacyDefaultAdmin(): void
    {
        $legacy = AdminUser::where('email', 'admin@templeportal.in')->first();
        if ($legacy !== null && ! $legacy->hasRole('super_admin')) {
            $legacy->assignRole('super_admin');
        }
    }
}
