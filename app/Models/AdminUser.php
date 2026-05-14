<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class AdminUser extends Authenticatable implements FilamentUser
{
    use HasPanelShield;
    use HasRoles;
    // LogsActivity records create/update/delete on this model into the
    // `activity_log` table (Spatie). We track admin lifecycle + role changes
    // for audit. See AdminUserResource for how role assignments are surfaced.
    use LogsActivity;

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

    /**
     * Panel access rules:
     *   1. Account must be active (admin can soft-disable staff)
     *   2. User must hold the `panel_user` permission
     *
     * The super_admin Gate::before bypass in AuthServiceProvider makes
     * `->can('panel_user')` always return true for super_admins, so we
     * don't special-case them here. A user with zero roles has zero
     * permissions, including `panel_user`, so they're correctly denied.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->can('panel_user');
    }

    protected string $guard_name = 'admin';

    public function assignedSevas(): HasMany
    {
        return $this->hasMany(Seva::class, 'assignee_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('admin_user');
    }
}
