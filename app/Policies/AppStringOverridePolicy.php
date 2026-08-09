<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\AppStringOverride;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added 2026-08-09 (RBAC audit G1).
 *
 * AppStringOverrideResource shipped with NO policy and NO permission slug.
 * Filament's authorize() helper returns Response::allow() when a resource's
 * model has no policy — it fails OPEN — so every panel user (volunteer,
 * pujari included) could create/edit/delete live app wording overrides that
 * are pushed to every installed phone. Without this class the
 * `app::string::override` permissions would never be consulted.
 */
class AppStringOverridePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_app::string::override');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('view_app::string::override');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_app::string::override');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('update_app::string::override');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('delete_app::string::override');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_app::string::override');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('force_delete_app::string::override');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_app::string::override');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('restore_app::string::override');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_app::string::override');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, AppStringOverride $appStringOverride): bool
    {
        return $adminUser->can('replicate_app::string::override');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_app::string::override');
    }
}
