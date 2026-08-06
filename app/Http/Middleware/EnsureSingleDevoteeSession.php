<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-active-login enforcement for devotee WEB sessions.
 *
 * Every fresh login (app or web) bumps devotees.auth_epoch via
 * Devotee::revokeOtherLogins(); each web session stores the epoch it was
 * created under. A mismatch means the devotee has since logged in
 * elsewhere — this session is force-logged-out and sent to /login.
 *
 * Applied only to the auth:devotee route group (never to guest/cached
 * paths — CacheGuestResponse and the Cloudflare edge rule are untouched).
 * Session rows can't be deleted per-devotee (the sessions table's
 * user_id is a bigint and the driver may be file), so the epoch check is
 * the driver-agnostic mechanism.
 */
class EnsureSingleDevoteeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $devotee = Auth::guard('devotee')->user();

        if ($devotee !== null) {
            $sessionEpoch = (int) $request->session()->get('devotee_auth_epoch', 0);

            if ($sessionEpoch !== (int) $devotee->auth_epoch) {
                Auth::guard('devotee')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // Gujarati-first hardcoded copy, matching the OTP error
                // strings in AuthWebController (no lang/ files exist).
                return redirect()
                    ->route('login')
                    ->withErrors(['phone' => 'તમે બીજા ડિવાઇસ પર લૉગિન કર્યું હોવાથી અહીંથી લૉગઆઉટ થયા છો. ફરી લૉગિન કરો.']);
            }
        }

        return $next($request);
    }
}
