<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = ['super_admin', 'trustee', 'accountant', 'staff', 'volunteer'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'admin']);
        }

        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@templeportal.in'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('admin123456'),
                'is_active' => true,
            ]
        );

        $admin->assignRole('super_admin');
    }
}
