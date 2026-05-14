<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Models\Role;

/**
 * Authorization for the bundled Shield Role resource (/admin/shield/roles).
 *
 * Spatie's Role model does NOT use SoftDeletes and never participates in
 * replicate/reorder flows, so the corresponding policy methods hard-deny
 * regardless of permission — keeps the surface tight even if a permission
 * row gets accidentally created. Super admin still bypasses via
 * Gate::before in AuthServiceProvider.
 */
class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_role');
    }

    public function view(AdminUser $adminUser, Role $role): bool
    {
        return $adminUser->can('view_role');
    }

    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_role');
    }

    public function update(AdminUser $adminUser, Role $role): bool
    {
        // Never let anyone but a super admin (via Gate::before) mutate the
        // super_admin role itself — preserves the bypass invariant.
        if ($role->name === 'super_admin') {
            return false;
        }

        return $adminUser->can('update_role');
    }

    public function delete(AdminUser $adminUser, Role $role): bool
    {
        // Same guard as update: super_admin role is immutable from the UI.
        if ($role->name === 'super_admin') {
            return false;
        }

        return $adminUser->can('delete_role');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_role');
    }

    public function forceDelete(AdminUser $adminUser, Role $role): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return false;
    }

    public function restore(AdminUser $adminUser, Role $role): bool
    {
        return false;
    }

    public function restoreAny(AdminUser $adminUser): bool
    {
        return false;
    }

    public function replicate(AdminUser $adminUser, Role $role): bool
    {
        return false;
    }

    public function reorder(AdminUser $adminUser): bool
    {
        return false;
    }
}
