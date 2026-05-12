<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coming-soon gatekeeper for the public website.
 *
 * When the `coming_soon_mode` SystemSetting is "1" (toggled on from
 * the admin dashboard), every non-admin / non-API / non-asset URL
 * renders a branded "coming soon" page with HTTP 503.
 *
 * Admin URLs (the Filament panel) and API routes are always allowed
 * through so the trust can edit settings, manage notifications, and
 * the mobile app keeps working even when the public site is hidden.
 */
class ComingSoonMode
{
    /** Paths that bypass the coming-soon screen no matter what. */
    private const BYPASS_PATHS = [
        'admin',
        'admin/*',
        'livewire/*',
        'filament/*',
        'api/*',
        'build/*',
        'images/*',
        'storage/*',
        'favicon*',
        '_opcache_reset.php',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        // Hot path — flag is checked on EVERY public request, so cache.
        // 60-second TTL keeps DB pressure trivial; the admin toggle
        // explicitly forgets this key after every flip, so the change
        // lands without waiting for the TTL.
        $enabled = Cache::remember(
            'system.coming_soon_mode',
            60,
            fn () => SystemSetting::getValue('coming_soon_mode') === '1',
        );

        if (! $enabled) {
            return $next($request);
        }

        return response()->view('pages.coming-soon', [], 503);
    }

    private function shouldBypass(Request $request): bool
    {
        foreach (self::BYPASS_PATHS as $pattern) {
            if ($request->is($pattern)) return true;
        }
        return false;
    }
}
