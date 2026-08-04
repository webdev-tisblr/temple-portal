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
    })->create();
