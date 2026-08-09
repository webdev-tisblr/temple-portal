<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\SevaSlotPool;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added 2026-08-09 (RBAC audit G3).
 *
 * Same shape as G2: `seva::slot::pool` was seeded and granted, but with no
 * policy class Filament fails OPEN. Slot pools drive seva capacity, so an
 * un-gated resource let any panel user change how many devotees can book.
 */
class SevaSlotPoolPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('view_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('update_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('delete_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('force_delete_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('restore_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, SevaSlotPool $sevaSlotPool): bool
    {
        return $adminUser->can('replicate_seva::slot::pool');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_seva::slot::pool');
    }
}
