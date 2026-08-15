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
        // Cloudflare sits in front of the origin, so without this EVERY
        // request looks like it came from a Cloudflare edge IP. Two things
        // broke on launch night as a result, both traced from the access log:
        //
        //   • Rate limits key on the client IP. Thousands of devotees were
        //     funnelled into ~39 buckets (one per edge IP), so the 10/min cap
        //     on /auth/otp/send burned out in seconds — 851 OTP sends and 640
        //     verifies answered 429, i.e. "Too many requests" on the login
        //     screen while the OTPs that DID get through sent perfectly.
        //   • Turnstile posts `remoteip` to siteverify. The token is issued
        //     for the devotee's real IP but was being validated against an
        //     edge IP, so it failed — "Verification failed. Please try again."
        //
        // Pinned to Cloudflare's own ranges rather than '*', because the
        // origin IP answers directly — a request straight to it with the
        // site's Host header serves the real site. With a wildcard, anyone
        // who knows that address could send a different X-Forwarded-For on
        // every request and get a fresh rate-limit bucket each time, which
        // is exactly the cap this is meant to restore. Honouring the header
        // only when the peer really is Cloudflare closes that off while
        // leaving devotee traffic resolving correctly.
        //
        // From https://www.cloudflare.com/ips/ — these change rarely. If
        // client IPs ever look wrong again, re-check that list first.
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);

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
