<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\HeroSlide;
use Illuminate\Auth\Access\HandlesAuthorization;

class HeroSlidePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the adminUser can view any models.
     */
    public function viewAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('view_any_hero::slide');
    }

    /**
     * Determine whether the adminUser can view the model.
     */
    public function view(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('view_hero::slide');
    }

    /**
     * Determine whether the adminUser can create models.
     */
    public function create(AdminUser $adminUser): bool
    {
        return $adminUser->can('create_hero::slide');
    }

    /**
     * Determine whether the adminUser can update the model.
     */
    public function update(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('update_hero::slide');
    }

    /**
     * Determine whether the adminUser can delete the model.
     */
    public function delete(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('delete_hero::slide');
    }

    /**
     * Determine whether the adminUser can bulk delete.
     */
    public function deleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('delete_any_hero::slide');
    }

    /**
     * Determine whether the adminUser can permanently delete.
     */
    public function forceDelete(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('force_delete_hero::slide');
    }

    /**
     * Determine whether the adminUser can permanently bulk delete.
     */
    public function forceDeleteAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('force_delete_any_hero::slide');
    }

    /**
     * Determine whether the adminUser can restore.
     */
    public function restore(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('restore_hero::slide');
    }

    /**
     * Determine whether the adminUser can bulk restore.
     */
    public function restoreAny(AdminUser $adminUser): bool
    {
        return $adminUser->can('restore_any_hero::slide');
    }

    /**
     * Determine whether the adminUser can replicate.
     */
    public function replicate(AdminUser $adminUser, HeroSlide $heroSlide): bool
    {
        return $adminUser->can('replicate_hero::slide');
    }

    /**
     * Determine whether the adminUser can reorder.
     */
    public function reorder(AdminUser $adminUser): bool
    {
        return $adminUser->can('reorder_hero::slide');
    }
}
