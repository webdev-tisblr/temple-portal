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
        'auth_epoch',
    ];

    protected $casts = [
        'language' => Language::class,
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'auth_epoch' => 'integer',
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

    /** Sanctum token name every mobile-app login mints. The discriminator
     *  that lets a web login evict web sessions WITHOUT touching the
     *  phone — see revokeOtherLogins(). Changing this string orphans
     *  tokens already in the field, so don't. */
    public const APP_TOKEN_NAME = 'mobile-app';

    /** Revoke only the app surface: Sanctum tokens + FCM registrations. */
    public const SCOPE_APP = 'app';

    /** Revoke only the web surface: other browser sessions (auth_epoch). */
    public const SCOPE_WEB = 'web';

    /** Revoke everything, everywhere (admin "sign out of all devices"). */
    public const SCOPE_ALL = 'all';

    /**
     * Single-active-login, scoped PER SURFACE.
     *
     * The two surfaces are enforced by two different mechanisms:
     *   • APP — Sanctum tokens named APP_TOKEN_NAME. Deleting them makes
     *     the phone 401 on its next call and log itself out. The FCM rows
     *     are detached at the same time so devotee-targeted pushes stop
     *     reaching a surrendered handset (the new one re-registers right
     *     after login); 'all' broadcasts still reach it.
     *   • WEB — the auth_epoch counter. Every web session stores the epoch
     *     it was born under; bumping it fails EnsureSingleDevoteeSession
     *     on every other session. (Session rows can't be deleted per
     *     devotee — the sessions table user_id is a bigint and the driver
     *     may be file — so the epoch is the driver-agnostic mechanism.)
     *
     * Until 2026-08-09 this did BOTH on every login. Since iOS donations
     * are forced onto the website (App Store 3.2.2(iv)), a devotee who
     * donated and re-authenticated in the browser had their phone's token
     * deleted underneath them — reported as "the app randomly terminates
     * my session" (spec 07, suspect #2). The surfaces now coexist: a web
     * login evicts other WEB sessions, an app login evicts other APP
     * tokens, and neither touches the other. Logging in on a second phone
     * still evicts the first phone, which is the security intent.
     *
     * Callers issue their own token / session AFTER this. Must NOT be
     * called at all from the app→web handoff (appLogin) — that is the
     * same login lineage, not a new device.
     *
     * @param  string  $scope  self::SCOPE_APP | SCOPE_WEB | SCOPE_ALL
     */
    public function revokeOtherLogins(string $scope = self::SCOPE_ALL): void
    {
        if ($scope === self::SCOPE_ALL) {
            // Nothing survives: every token regardless of name.
            $this->tokens()->delete();
            $this->deviceTokens()->update(['devotee_id' => null]);
            $this->increment('auth_epoch');

            return;
        }

        if ($scope === self::SCOPE_APP) {
            $this->tokens()->where('name', self::APP_TOKEN_NAME)->delete();
            $this->deviceTokens()->update(['devotee_id' => null]);
        }

        if ($scope === self::SCOPE_WEB) {
            // NOTE: no deviceTokens() detach here. A web login used to
            // unsubscribe the devotee's phone from targeted pushes — a
            // silent second bug that came with the global revoke.
            $this->increment('auth_epoch');
        }
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
            // Seed the language from the request locale (API: X-Locale via
            // SetApiLocale, web: cookie via SetLocale — both run before this),
            // so a Hindi/English signup doesn't default to 'gu'. Existing
            // devotees are never overwritten here.
            'language' => in_array(app()->getLocale(), ['gu', 'hi', 'en'], true) ? app()->getLocale() : 'gu',
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ]);
        return [$devotee, true];
    }
}
