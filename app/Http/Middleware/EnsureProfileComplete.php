<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Devotees with no name on file are held at /profile/complete until they
 * fill the form in.
 *
 * redirect()->guest() rather than redirect()->route(): guest() records
 * session('url.intended') for us — the full URL on a GET, the previous
 * URL on a POST — which DashboardController::saveCompleteProfile() then
 * consumes. Without it this was a dead end: completing a profile dumped
 * everyone on /dashboard and lost the seva/hall/cart they were mid-way
 * through (item 3.1).
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $devotee = Auth::guard('devotee')->user();

        if ($devotee && empty($devotee->name)) {
            return redirect()->guest(route('profile.complete'));
        }

        return $next($request);
    }
}
