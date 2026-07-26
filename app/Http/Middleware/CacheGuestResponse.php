<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side full-page cache for the heavy GUEST pages (launch W2).
 *
 * Load test 2026-07-26: uncached, 2 vCPUs render ~50 pages/s with p95
 * ~2s at 300 concurrent — every hit re-renders Blade + queries. These
 * pages are identical for every logged-out visitor, so the first render
 * per (path × locale) is stored in Redis for a short TTL and everyone
 * else gets the HTML in a few milliseconds. Cloudflare edge rules sit
 * in front of this later; this layer alone multiplies origin capacity
 * and also covers logged-out devotees who bypass the edge cache.
 *
 * Deliberately conservative:
 *   • GET + 200 only, no query strings (avoids cache-key explosion),
 *     guests only (any devotee/admin login bypasses entirely).
 *   • PATH WHITELIST — pages verified to carry no <form>/CSRF POST
 *     surface. A cached page embeds the first renderer's CSRF token,
 *     so any page a user can POST from must NEVER be listed here.
 *   • Keyed by resolved locale (runs after SetLocale), so gu/hi/en
 *     each cache their own copy.
 *   • Skips responses rendered with flashed status/errors.
 *   • 120s TTL: admin edits appear within 2 minutes; no invalidation
 *     plumbing to maintain or get wrong.
 */
class CacheGuestResponse
{
    private const TTL_SECONDS = 120;

    /** Exact paths ('' = home) and prefixes that are safe to cache. */
    private const PATHS = ['', 'darshan', 'gallery', 'projects', 'trustees'];

    private const PREFIXES = ['pages/']; // CMS pages incl. the app's /embed views

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isCacheable($request)) {
            return $next($request);
        }

        $key = 'pagecache:'.app()->getLocale().':'.sha1($request->path());

        $cached = Cache::get($key);
        if (is_string($cached)) {
            return response($cached)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Page-Cache', 'hit');
        }

        $response = $next($request);

        if (
            $response->getStatusCode() === 200
            && ! $request->session()->has('errors')
            && ! $request->session()->has('status')
            && is_string($response->getContent())
            && $response->getContent() !== ''
        ) {
            Cache::put($key, $response->getContent(), self::TTL_SECONDS);
            $response->headers->set('X-Page-Cache', 'miss');
        }

        return $response;
    }

    private function isCacheable(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->query() !== []) {
            return false;
        }

        if (auth('devotee')->check() || auth('admin')->check()) {
            return false;
        }

        $path = trim($request->path(), '/');
        $path = $path === '/' ? '' : $path;

        if (in_array($path, self::PATHS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
