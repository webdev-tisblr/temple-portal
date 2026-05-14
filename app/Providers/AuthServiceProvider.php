<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AdminUser;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Map of policy class registrations.
     *
     * Filament Shield auto-discovers policies under app/Policies/, so we don't
     * list them here. New custom policies that aren't tied to a Filament
     * resource (e.g., a service-layer policy) should be registered here.
     */
    protected $policies = [
        //
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
        |--------------------------------------------------------------------------
        | Super Admin Gate::before bypass
        |--------------------------------------------------------------------------
        |
        | Any AdminUser holding the `super_admin` role short-circuits every
        | authorization check ($user->can('whatever')) to true. This is the
        | single, grep-able place that grants global authority — never check
        | for `super_admin` inline elsewhere in the code.
        |
        | Returning `null` (NOT `false`) lets non-super-admin users fall
        | through to the actual policy / permission check; returning `false`
        | here would deny every gate for every user.
        |
        | We intentionally restrict the bypass to AdminUser instances so a
        | hypothetical Devotee with a stray `super_admin` role (impossible
        | by design, defensive nonetheless) cannot escalate via the API.
        */
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof AdminUser) {
                return null;
            }

            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
