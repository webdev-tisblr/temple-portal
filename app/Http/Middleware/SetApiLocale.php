<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    /**
     * Resolve the request locale for API responses so localized accessors
     * (title, name, share_text, …) return the caller's language.
     *
     * The Flutter app sends its active language as an X-Locale header;
     * ?lang= is honoured as a fallback for manual testing. Defaults to
     * Gujarati, matching the web middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('X-Locale') ?? $request->query('lang', 'gu');

        if (! in_array($locale, ['gu', 'hi', 'en'], true)) {
            $locale = 'gu';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
