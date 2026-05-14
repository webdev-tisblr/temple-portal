<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\NotificationLog;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * NotificationLog is a write-only stream maintained by NotificationService.
 * Admins read the rows from the Filament UI; nobody creates / updates /
 * deletes through normal flows. The hard-disabled methods stay
 * permission-checked anyway in case Shield's UI tries to enable them.
 */
class NotificationLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_notification::log');
    }

    public function view(AdminUser $adminUser, NotificationLog $log): bool
    {
        return $adminUser->can('view_notification::log');
    }

    public function create(AdminUser $adminUser): bool
    {
        return false;
    }

    public function update(AdminUser $adminUser, NotificationLog $log): bool
    {
        return false;
    }

    public function delete(AdminUser $adminUser, NotificationLog $log): bool
    {
        return $adminUser->can('delete_notification::log');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_notification::log');
    }

    public function forceDelete(AdminUser $adminUser, NotificationLog $log): bool
    {
        return false;
    }

    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return false;
    }

    public function restore(AdminUser $adminUser, NotificationLog $log): bool
    {
        return false;
    }

    public function restoreAny(AdminUser $adminUser): bool
    {
        return false;
    }

    public function replicate(AdminUser $adminUser, NotificationLog $log): bool
    {
        return false;
    }

    public function reorder(AdminUser $adminUser): bool
    {
        return false;
    }
}
