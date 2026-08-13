<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\HallReminderRule;
use Illuminate\Auth\Access\HandlesAuthorization;

class HallReminderRulePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('view_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('update_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('delete_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('force_delete_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('restore_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, HallReminderRule $hallReminderRule): bool
    {
        return $adminUser->can('replicate_hall::reminder::rule');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_hall::reminder::rule');
    }
}
