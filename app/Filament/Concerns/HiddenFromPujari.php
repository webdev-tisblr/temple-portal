<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Hides a Filament Resource from the `pujari` role.
 *
 * Pujari is a restricted admin role intended for priests who only need
 * visibility into the seva bookings they'll perform — they don't see
 * donations, store, users, settings, content, anything else. Every
 * resource in app/Filament/Resources/ uses this trait EXCEPT
 * SevaBookingResource.
 *
 * Two static overrides cover both surfaces a resource exposes:
 *   • canViewAny()              — the route-level gate, also stops
 *                                  pujari from typing /admin/x in the URL
 *   • shouldRegisterNavigation() — keeps the sidebar item out of the
 *                                  nav menu so pujari never sees a
 *                                  resource they can't open
 *
 * Other roles fall through to parent::canViewAny() / parent::shouldRegisterNavigation()
 * so this is purely additive — no existing role sees its access change.
 */
trait HiddenFromPujari
{
    public static function canViewAny(): bool
    {
        if (auth('admin')->user()?->hasRole('pujari')) {
            return false;
        }
        return parent::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth('admin')->user()?->hasRole('pujari')) {
            return false;
        }
        return parent::shouldRegisterNavigation();
    }
}
