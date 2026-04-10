<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class AdminUser extends Authenticatable implements FilamentUser
{
    use HasRoles;

    protected $table = 'temple_admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_path',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    protected string $guard_name = 'admin';

    public function assignedSevas(): HasMany
    {
        return $this->hasMany(Seva::class, 'assignee_id');
    }
}
