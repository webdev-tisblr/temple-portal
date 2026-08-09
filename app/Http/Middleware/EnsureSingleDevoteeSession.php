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
 * elsewhere — this session is dead.
 *
 * Runs on EVERY web request (appended to the web group, before
 * CacheGuestResponse): a superseded session is logged out and the
 * request CONTINUES as a guest. That keeps the whole site consistent —
 * header, public pages and protected pages all agree instantly.
 *
 * ⚠️ On PROTECTED routes we must answer the redirect ourselves. The
 * original comment here assumed auth:devotee runs after this middleware;
 * it does not. Laravel's $middlewarePriority sorts
 * Illuminate\Auth\Middleware\Authenticate ahead of every non-priority
 * middleware appended to the web group, so by the time we log the
 * devotee out, auth:devotee has already waved the request through — and
 * the controller would then run with a null devotee and 500 (confirmed:
 * DashboardController::index line 39). Hence the explicit bounce below.
 *
 * Guests cost nothing here (guard->user() is null). Running before
 * CacheGuestResponse means a just-logged-out request is a plain guest
 * request by the time caching decisions happen — no half-authed
 * responses can be cached.
 *
 * Session rows can't be deleted per-devotee (the sessions table's
 * user_id is a bigint and the driver may be file), so the epoch check
 * is the driver-agnostic mechanism.
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

                if ($this->routeRequiresDevotee($request)) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Unauthenticated.'], 401);
                    }

                    // guest() records where they were, so re-logging in
                    // brings them straight back (item 3.1).
                    return redirect()->guest(route('login'));
                }
            }
        }

        return $next($request);
    }

    /**
     * Does this route need a logged-in devotee? Read off the route's own
     * middleware list rather than hardcoding paths, so new protected
     * routes are covered automatically.
     */
    private function routeRequiresDevotee(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:devotee')) {
                return true;
            }
        }

        return false;
    }
}
