<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * through so the trust can edit settings and manage notifications.
 *
 * The mobile app needs more than `api/*`: it also opens CMS pages in a
 * WebView and hands its session to the web for the iOS donate flow. Those,
 * the already-delivered signed receipt links, the payment return URLs and
 * the store-review legal pages all stay reachable — hiding the website must
 * not break links that are already out in the world.
 *
 * NOTE: the Cloudflare edge Cache Rule caches `/`, `darshan`, `gallery`,
 * `projects`, `trustees` and `pages/*`. Because this gate answers 503,
 * Cloudflare's stale-on-error will keep serving its cached copies of those
 * pages indefinitely. Flipping `coming_soon_mode` in either direction
 * REQUIRES a Cloudflare cache purge.
 */
class ComingSoonMode
{
    /** Paths that bypass the coming-soon screen no matter what. */
    private const BYPASS_PATHS = [
        'admin',
        'admin/*',
        'admin-tools/*',
        'livewire/*',
        'filament/*',
        'api/*',
        // The live donor display board runs on a screen in the temple hall and
        // must keep working while the public site is hidden — the launch event
        // is precisely when coming-soon mode is most likely to still be on.
        // Without this the hall screen serves the 503 "ટૂંક સમયમાં" page.
        'board',
        'board/*',
        'build/*',
        'images/*',
        'storage/*',
        'favicon*',
        '_opcache_reset.php',

        // Health checks. UptimeRobot monitors /up/deep, and `up` on its own
        // is an exact match that does NOT cover it.
        'up',
        'up/*',

        // Permanent signed receipt/invoice links, already embedded in
        // WhatsApp/email confirmations that devotees still have.
        'r/*',

        // Flutter WebView pages + the app->web session handoff.
        'pages/*/embed',
        'auth/app-login',

        // Payment returns — someone who has just paid must never land on
        // the coming-soon page.
        'seva/booking/success',
        'seva/booking/failure',
        'store/order/success',
        'store/order/failure',
        'hall-booking/success',
        'hall-booking/failure',
        'donate/thank-you',
        'seva/greeting-card/*',
        'donate/greeting-card/*',

        // Apple/Google reviewers open these during store review.
        'privacy-policy',
        'terms',
        'refund-policy',
        'account-deletion',
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

        // A launch time that has passed opens the site, whatever the flag
        // still says. `site:launch` flips the stored flag a minute later, but
        // THIS check is what actually guarantees the doors open on time: if
        // the scheduler is wedged, a deploy is mid-flight, or someone kills
        // the cron, the site still goes live at the advertised moment rather
        // than waiting for a human to notice.
        $launchAt = static::launchAt();

        if ($launchAt !== null && $launchAt->isPast()) {
            return $next($request);
        }

        // CDN-Cache-Control is what Cloudflare actually obeys; without it the
        // edge can pin this page and keep serving it after the flag goes off.
        return response()
            ->view('pages.coming-soon', ['launchAt' => $launchAt], 503)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Retry-After', '3600');
    }

    /**
     * The configured launch moment, or NULL when the trust has not set one.
     *
     * Cached alongside the flag because it is read on the same hot path.
     * Parsed in the app timezone (IST): the admin types a wall-clock time
     * and means exactly that, so a stored '2026-08-15 09:00:00' must not be
     * read as UTC and open the site five and a half hours early.
     */
    public static function launchAt(): ?Carbon
    {
        $raw = Cache::remember(
            'system.launch_at',
            60,
            fn (): string => (string) SystemSetting::getValue('launch_at', ''),
        );

        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            // A malformed value must never take the site down or open it
            // early — treat it as "no launch time set".
            return null;
        }
    }

    private function shouldBypass(Request $request): bool
    {
        foreach (self::BYPASS_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
