<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API twin of EnsureProfileComplete.
 *
 * Until 2026-08-21 only the WEBSITE refused to transact for a devotee
 * with no name on file — /api/v1 had no equivalent gate at all, so the
 * app could (and did) book sevas, place orders and donate from accounts
 * created with `name => ''`. The consequences landed downstream: every
 * WhatsApp template binds the devotee's name, and Meta rejects a
 * template message whose parameter is an empty string, so those
 * devotees silently received NOTHING — no booking confirmation, no
 * receipt, no greeting card.
 *
 * The client is gated too (the app's router holds a nameless devotee on
 * /profile), but this is the layer that matters: builds already in the
 * field can't be patched, and the app can be routed around. Answering
 * 422 here fixes every version at once.
 *
 * Deliberately NOT applied to /payments/verify — by the time that call
 * happens money has already moved, and refusing to record it would be
 * strictly worse than a nameless capture.
 */
class EnsureApiProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $devotee = $request->user();

        if ($devotee !== null && ! $devotee->hasCompleteProfile()) {
            return response()->json([
                'success' => false,
                // Locale comes from X-Locale via SetApiLocale, so this
                // reads in the devotee's own language.
                'message' => __('auth.profile_incomplete'),
                // Machine-readable discriminator: the app routes on this
                // rather than string-matching the message. Older builds
                // ignore it and simply show the message, which is why the
                // message has to stand on its own.
                'code' => 'profile_incomplete',
                'errors' => ['name' => [__('auth.profile_incomplete')]],
            ], 422);
        }

        return $next($request);
    }
}
