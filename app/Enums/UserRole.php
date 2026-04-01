<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case TRUSTEE = 'trustee';
    case ACCOUNTANT = 'accountant';
    case STAFF = 'staff';
    case VOLUNTEER = 'volunteer';
}
