<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Language;
use App\Models\Concerns\HasManagedImages;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Devotee extends Authenticatable
{
    use HasApiTokens, HasManagedImages, HasUuid, SoftDeletes;

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
     * Resolve a devotee for OTP login: find by phone (including
     * soft-deleted rows), restore if trashed, create if missing.
     * Stamps verification + login timestamps in every branch.
     *
     * The withTrashed() lookup is the critical bit. The phone column
     * has a UNIQUE constraint that DOES NOT ignore soft-deleted rows
     * (MySQL treats tombstones like live rows for unique indexes), so
     * a plain firstOrCreate() crashes with SQLSTATE[23000] the moment
     * a phone has ever been associated with a since-deleted devotee.
     *
     * Returns [Devotee, wasNew] — `wasNew` only true for first-ever
     * signups (a restored tombstone is treated as a returning user
     * so the welcome notification doesn't fire twice).
     *
     * @return array{0: self, 1: bool}
     */
    public static function resolveForLogin(string $phone): array
    {
        $devotee = static::withTrashed()->where('phone', $phone)->first();

        if (! $devotee) {
            $devotee = static::create([
                'phone' => $phone,
                'name' => '',
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ]);
            return [$devotee, true];
        }

        if ($devotee->trashed()) {
            $devotee->restore();
        }

        $devotee->update([
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        return [$devotee, false];
    }
}
