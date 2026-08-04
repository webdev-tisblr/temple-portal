<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\GalleryCategory;

class GalleryCategoryPolicy
{
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_gallery::category');
    }

    public function view(AdminUser $adminUser, GalleryCategory $galleryCategory): bool
    {
        return $adminUser->can('view_gallery::category');
    }

    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_gallery::category');
    }

    public function update(AdminUser $adminUser, GalleryCategory $galleryCategory): bool
    {
        return $adminUser->can('update_gallery::category');
    }

    public function delete(AdminUser $adminUser, GalleryCategory $galleryCategory): bool
    {
        return $adminUser->can('delete_gallery::category');
    }

    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_gallery::category');
    }
}
