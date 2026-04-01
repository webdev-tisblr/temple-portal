<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = 'gu';

        // Check URL prefix
        $segment = $request->segment(1);
        if (in_array($segment, ['hi', 'en'])) {
            $locale = $segment;
        }

        // Query param override
        if ($request->has('lang') && in_array($request->query('lang'), ['gu', 'hi', 'en'])) {
            $locale = $request->query('lang');
            cookie()->queue('locale', $locale, 60 * 24 * 365);
        }

        // Cookie fallback (only if no URL prefix or query param)
        if ($segment !== 'hi' && $segment !== 'en' && !$request->has('lang')) {
            $cookieLocale = $request->cookie('locale');
            if ($cookieLocale && in_array($cookieLocale, ['gu', 'hi', 'en'])) {
                $locale = $cookieLocale;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
