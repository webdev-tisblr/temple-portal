<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\DailyDarshanPhoto;
use Illuminate\Auth\Access\HandlesAuthorization;

class DailyDarshanPhotoPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('view_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('update_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('delete_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('force_delete_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('restore_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, DailyDarshanPhoto $dailyDarshanPhoto): bool
    {
        return $adminUser->can('replicate_daily::darshan::photo');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_daily::darshan::photo');
    }
}
