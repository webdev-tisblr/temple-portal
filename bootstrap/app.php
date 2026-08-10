<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Coming-soon gate. Renders pages/coming-soon when the
            // admin has flipped the toggle on the dashboard.
            // Bypasses admin / api / asset paths internally.
            \App\Http\Middleware\ComingSoonMode::class,
            \App\Http\Middleware\SetLocale::class,
            // Single-active-login: a devotee session superseded by a login
            // on another device is logged out here and the request
            // continues as a guest — on EVERY page, so the header and
            // protected routes always agree. MUST run before
            // CacheGuestResponse so the just-logged-out request is a
            // plain guest by the time caching decisions happen.
            \App\Http\Middleware\EnsureSingleDevoteeSession::class,
            // Guest full-page cache — MUST run after SetLocale (cache
            // key includes the resolved locale) and after ComingSoonMode
            // (never caches over the gate's short-circuit).
            \App\Http\Middleware\CacheGuestResponse::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ], append: [
            // Resolve the app's language (X-Locale header) so localized
            // model accessors serialise in the caller's locale.
            \App\Http\Middleware\SetApiLocale::class,
        ]);
        $middleware->encryptCookies(except: ['locale']);
        $middleware->alias([
            'profile.complete' => \App\Http\Middleware\EnsureProfileComplete::class,
            // Cloudflare Turnstile server check — inert until the admin
            // sets the keys in System Settings → Cloudflare Turnstile.
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Sentry error tracking — inert until SENTRY_LARAVEL_DSN is set
        // in the environment (production VPS only).
        \Sentry\Laravel\Integration::handles($exceptions);

        // A stale CSRF token normally means the session already died —
        // which, on the way OUT, is the state the devotee was asking for.
        // Showing them Laravel's "419 Page Expired" there is a dead end:
        // they are stuck on an error page, still apparently signed in,
        // with no way to complete the thing they clicked. So finish the
        // job: tear the session down and send them home.
        //
        // Everywhere else a 419 is genuine (a form sat open too long), so
        // it bounces back to the form with a message rather than a wall.
        $exceptions->render(function (
            \Illuminate\Session\TokenMismatchException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.session_expired')], 419);
            }

            if ($request->is('logout')) {
                \Illuminate\Support\Facades\Auth::guard('devotee')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('home');
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'code', 'pan_number']))
                ->with('error', __('auth.session_expired'));
        });
    })->create();
