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
        'contact::submission',
        'daily::darshan::photo',
        'devotee',
        'donation',
        'donation::campaign',
        'donation::type',
        'event',
        'gallery',
        'gallery::category',
        'hall',
        'hall::booking',
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
        'DarshanTimingsPage',
        'HomePageSettingsPage',
        'FinancialReports',
        'SystemSettings',
    ];

    private const WIDGETS = [
        'ComingSoonToggleWidget',
        'DonationChart',
        'DonationStatsOverview',
        'QueueHealthOverview',
        'RecentDonationsTable',
        'SevaBookingOverview',
    ];

    /**
     * Cross-cutting permissions that aren't tied to a single Filament resource.
     * Add new action-level permissions here as the app grows.
     */
    private const CUSTOM_PERMISSIONS = [
        // Required by AdminUser::canAccessPanel — without this, even an
        // is_active admin with roles cannot enter /admin.
        'panel_user',

        // Financial actions surfaced as buttons in Filament resources.
        'approve_refund',           // Issue a Razorpay refund on an Order/Donation
        'regenerate_80g_receipt',   // Re-render and re-deliver a tax receipt
        'export_donations',         // CSV/XLSX export from donation list
        'export_orders',            // CSV/XLSX export from order list

        // Communication actions.
        'resend_notification',      // Retry a failed notification row
        'send_announcement',        // Push an announcement to all devotees

        // Operational actions.
        'assign_seva_to_pujari',    // Assign / reassign pujari on a SevaBooking
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
                $crud('gallery'),
                $crud('gallery::category'),
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
                $crud('seva::booking', ['view_any', 'view', 'update', 'delete', 'delete_any']),
                $crud('hall::booking', ['view_any', 'view', 'update', 'delete', 'delete_any']),
                $crud('order', ['view_any', 'view', 'update']),
                $crud('donation', ['view_any', 'view', 'create', 'update']),

                // People
                $crud('devotee', ['view_any', 'view', 'update']),
                $readOnly('admin::user'),

                // Communication
                $crud('notification::template'),
                $crud('hero::slide'),
                $crud('status::template'),
                $crud('seva::reminder::rule'),
                $crud('notification', ['view_any', 'view', 'create', 'update', 'delete', 'delete_any']),
                $readOnly('notification::log'),
                $readOnly('contact::submission'),
                $crud('trustee'),

                // Pages, widgets, actions
                ['page_FinancialReports', 'page_SystemSettings', 'page_DarshanTimingsPage', 'page_HomePageSettingsPage'],
                array_map(fn ($w) => "widget_{$w}", self::WIDGETS),
                [
                    'approve_refund',
                    'regenerate_80g_receipt',
                    'export_donations',
                    'export_orders',
                    'resend_notification',
                    'send_announcement',
                    'assign_seva_to_pujari',
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
                    'widget_RecentDonationsTable',
                ],
                [
                    'approve_refund',
                    'regenerate_80g_receipt',
                    'export_donations',
                    'export_orders',
                ],
            ),

            // ---- Staff: front-desk operations. -------------------------
            'staff' => array_merge(
                ['panel_user'],
                $crud('devotee'),
                $crud('seva::booking', ['view_any', 'view', 'create', 'update']),
                $crud('hall::booking', ['view_any', 'view', 'create', 'update']),
                $crud('order', ['view_any', 'view', 'update']),
                $crud('contact::submission'),
                $readOnly('seva'),
                $readOnly('hall'),
                $readOnly('product'),
                $readOnly('event'),
                $readOnly('gallery'),
                $readOnly('daily::darshan::photo'),
                $readOnly('donation'),
                $readOnly('donation::campaign'),
                [
                    'widget_ComingSoonToggleWidget',
                    'widget_SevaBookingOverview',
                    'widget_RecentDonationsTable',
                ],
                ['resend_notification'],
            ),

            // ---- Volunteer: light read-only. ---------------------------
            'volunteer' => array_merge(
                ['panel_user'],
                $readOnly('devotee'),
                $readOnly('seva::booking'),
                $readOnly('event'),
                $readOnly('gallery'),
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
                ['widget_SevaBookingOverview'],
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
