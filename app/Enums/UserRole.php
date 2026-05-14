<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seeded role presets. New roles created at runtime by the super admin
 * in the Filament UI will NOT appear here — this enum is for code paths
 * that need to refer to the bundled roles by name (seeders, tests, default
 * assignments). Use $user->hasRole('whatever') for runtime checks; don't
 * import this enum just to compare strings.
 */
enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case TRUSTEE = 'trustee';
    case ACCOUNTANT = 'accountant';
    case STAFF = 'staff';
    case VOLUNTEER = 'volunteer';
    case PUJARI = 'pujari';
}
