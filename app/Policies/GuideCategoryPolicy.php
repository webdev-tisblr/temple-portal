<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\GuideCategory;
use Illuminate\Auth\Access\HandlesAuthorization;

class GuideCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_guide::category');
    }

    public function view(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('view_guide::category');
    }

    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_guide::category');
    }

    public function update(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('update_guide::category');
    }

    public function delete(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('delete_guide::category');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_guide::category');
    }

    public function forceDelete(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('force_delete_guide::category');
    }

    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_guide::category');
    }

    public function restore(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('restore_guide::category');
    }

    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_guide::category');
    }

    public function replicate(AdminUser $adminUser, GuideCategory $guideCategory): bool
    {
        return $adminUser->can('replicate_guide::category');
    }

    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_guide::category');
    }
}
