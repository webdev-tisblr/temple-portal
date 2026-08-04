<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\SevaCategory;

class SevaCategoryPolicy
{
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_seva::category');
    }

    public function view(AdminUser $adminUser, SevaCategory $sevaCategory): bool
    {
        return $adminUser->can('view_seva::category');
    }

    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_seva::category');
    }

    public function update(AdminUser $adminUser, SevaCategory $sevaCategory): bool
    {
        return $adminUser->can('update_seva::category');
    }

    public function delete(AdminUser $adminUser, SevaCategory $sevaCategory): bool
    {
        return $adminUser->can('delete_seva::category');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_seva::category');
    }
}
