<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Guide;
use Illuminate\Auth\Access\HandlesAuthorization;

class GuidePolicy
{
    use HandlesAuthorization;

    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_guide');
    }

    public function view(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('view_guide');
    }

    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_guide');
    }

    public function update(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('update_guide');
    }

    public function delete(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('delete_guide');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_guide');
    }

    public function forceDelete(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('force_delete_guide');
    }

    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_guide');
    }

    public function restore(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('restore_guide');
    }

    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_guide');
    }

    public function replicate(AdminUser $adminUser, Guide $guide): bool
    {
        return $adminUser->can('replicate_guide');
    }

    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_guide');
    }
}
