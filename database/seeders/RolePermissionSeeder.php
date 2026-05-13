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

        // `pujari` — restricted role that only sees the Seva Bookings
        // resource in the admin. Gated via the HiddenFromPujari trait
        // on every other resource. Used when the temple wants to give
        // a priest visibility into upcoming sevas without exposing
        // donations / store / users / settings.
        $roles = ['super_admin', 'trustee', 'accountant', 'staff', 'volunteer', 'pujari'];

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
