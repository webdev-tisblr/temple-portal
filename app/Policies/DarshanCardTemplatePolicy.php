<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\DarshanCardTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added 2026-08-09 (RBAC audit G2).
 *
 * The `darshan::card::template` permissions were already seeded and granted
 * to `trustee`, but no policy class existed — so Filament fell back to its
 * fail-OPEN path and the permissions were never consulted. This class makes
 * the existing grants actually mean something.
 */
class DarshanCardTemplatePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('view_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('update_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('delete_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('force_delete_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('restore_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, DarshanCardTemplate $darshanCardTemplate): bool
    {
        return $adminUser->can('replicate_darshan::card::template');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_darshan::card::template');
    }
}
