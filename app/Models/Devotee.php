<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Language;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Devotee extends Authenticatable
{
    use HasApiTokens, HasUuid, SoftDeletes;

    protected $table = 'temple_devotees';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'pan_encrypted',
        'pan_last_four',
        'address',
        'city',
        'state',
        'pincode',
        'country',
        'date_of_birth',
        'language',
        'profile_photo_path',
        'is_active',
        'phone_verified_at',
        'last_login_at',
    ];

    protected $casts = [
        'language' => Language::class,
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function sevaBookings(): HasMany
    {
        return $this->hasMany(SevaBooking::class, 'devotee_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'devotee_id');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'devotee_id');
    }

    public function hallBookings(): HasMany
    {
        return $this->hasMany(HallBooking::class, 'devotee_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'devotee_id');
    }
}
