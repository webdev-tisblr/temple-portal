<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * One place that decides where a devotee is allowed to be sent after an
 * auth interstitial (login, OTP, profile completion).
 *
 * Laravel already writes `session('url.intended')` on every guest bounce
 * (`Redirector::guest()`), but nothing ever consumed it — every login
 * landed on /dashboard (item 3.1). Two things were missing:
 *
 *   1. a consumer  → self::intended()
 *   2. a way for a *clicked* login link (never a bounce, so no intended
 *      URL exists) to carry its own destination → self::loginUrl() /
 *      `?next=` → self::remember()
 *
 * THE RULE (single definition, used by both paths): a destination is only
 * ever a SAME-HOST, SITE-RELATIVE path. Rejected outright:
 *   • absolute URLs on another host (`https://evil.test/x`)
 *   • protocol-relative URLs (`//evil.test/x`)
 *   • backslashes (`/\evil.test` — browsers normalise `\` to `/`, so this
 *     is an off-host redirect wearing a relative-path costume)
 *   • control characters (NUL, CR/LF response splitting)
 *   • the auth routes themselves (`/login*`, `/logout`, `/auth/*`,
 *     `/profile/complete`) — landing back on those would loop forever
 *
 * Absolute URLs on OUR OWN host are accepted and downgraded to a path,
 * because that is the shape `Redirector::guest()` stores.
 *
 * Never trust `next` (or anything pulled out of the session) without
 * passing it through here first.
 */
final class SafeRedirect
{
    /**
     * Destinations that would bounce the devotee straight back into the
     * flow they just finished. Matched on the PATH only (query ignored).
     */
    private const DENIED_PREFIXES = [
        '/login',
        '/logout',
        '/auth',
        '/profile/complete',
    ];

    /**
     * Validate a site-relative destination. Returns the path (with its
     * query/fragment preserved) or null when it breaks the rule above.
     */
    public static function sanitize(mixed $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        // Control chars: NUL terminators and CR/LF header injection.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        // Browsers treat "\" as "/", so "/\evil.test" leaves the site.
        if (str_contains($candidate, '\\')) {
            return null;
        }

        // Site-relative only, and never protocol-relative "//host".
        if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false) {
            return null;
        }

        // A relative path can carry none of these. Belt-and-braces against
        // shapes parse_url reads differently from a browser.
        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        if (self::isDeniedPath($parts['path'] ?? '/')) {
            return null;
        }

        return $candidate;
    }

    /**
     * Same rule as sanitize(), but also accepts an absolute URL on our own
     * host and reduces it to a path. This is what session('url.intended')
     * holds after Redirector::guest().
     */
    public static function normalize(mixed $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            return self::sanitize($candidate);
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        if (! self::isOwnHost($parts['host'])) {
            return null;
        }

        $rebuilt = $parts['path'] ?? '/';
        $rebuilt = $rebuilt === '' ? '/' : $rebuilt;

        if (isset($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return self::sanitize($rebuilt);
    }

    /**
     * Record a destination for the next intended() call. Silently ignores
     * anything that fails the rule (a bad `next` must degrade to the
     * default landing page, never to an error).
     */
    public static function remember(mixed $candidate): bool
    {
        $safe = self::normalize($candidate);

        if ($safe === null) {
            return false;
        }

        Session::put('url.intended', $safe);

        return true;
    }

    /**
     * Consume the stored destination, re-validating it on the way out, and
     * fall back when there isn't one (or it no longer passes the rule).
     */
    public static function intended(string $fallback): string
    {
        $stored = Session::pull('url.intended');

        return self::normalize($stored) ?? $fallback;
    }

    /**
     * A login URL that remembers where the devotee currently is, so
     * "Login to book this seva" returns to that seva instead of the
     * dashboard. Defaults to the current request URL.
     *
     * Exposed to Blade as the login_url() helper.
     */
    public static function loginUrl(?string $next = null): string
    {
        $next ??= request()?->fullUrl();

        $safe = self::normalize($next);

        return $safe === null
            ? route('login')
            : route('login', ['next' => $safe]);
    }

    private static function isDeniedPath(string $path): bool
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        foreach (self::DENIED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function isOwnHost(string $host): bool
    {
        $host = strtolower($host);

        $allowed = array_filter([
            strtolower((string) request()?->getHost()),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]);

        return in_array($host, $allowed, true);
    }
}
