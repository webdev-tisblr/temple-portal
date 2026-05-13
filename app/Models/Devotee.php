<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Language;
use App\Models\Concerns\HasManagedImages;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Devotee extends Authenticatable
{
    use HasApiTokens, HasManagedImages, HasUuid;

    protected $table = 'temple_devotees';

    protected function managedImages(): array
    {
        return ['profile_photo_path' => 'r2'];
    }

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

    /**
     * Resolve a devotee for OTP login: find by phone, create if missing.
     * Stamps verification + login timestamps in every branch.
     *
     * Returns [Devotee, wasNew]. wasNew is true only for first-ever
     * signups, so the devotee.registered welcome notification fires
     * once.
     *
     * Earlier versions of this method used withTrashed() + restore()
     * to work around a SoftDeletes tombstone bug — phone has a UNIQUE
     * index that MySQL doesn't relax for soft-deleted rows, so a
     * tombstone with the same phone broke every subsequent login.
     * The SoftDeletes trait was dropped from this model (and ten
     * others) in the 2026_05_13 migration; tombstones no longer exist.
     *
     * @return array{0: self, 1: bool}
     */
    public static function resolveForLogin(string $phone): array
    {
        $devotee = static::where('phone', $phone)->first();

        if ($devotee) {
            $devotee->update([
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ]);
            return [$devotee, false];
        }

        $devotee = static::create([
            'phone' => $phone,
            'name' => '',
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ]);
        return [$devotee, true];
    }
}
