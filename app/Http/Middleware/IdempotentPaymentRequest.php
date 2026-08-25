<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the four payment-initiating POSTs safe to send twice.
 *
 * Why this exists (2026-08-25). Every one of these endpoints has to call
 * Razorpay's order API before it can render anything, which on a slow
 * connection is one to three seconds of an apparently frozen page. The
 * devotee presses the button again. The browser aborts the first request
 * and sends a second — but PHP-FPM does not stop working on the first,
 * because Laravel only writes output at the very end and that is the only
 * point a client disconnect would be noticed. So BOTH run to completion:
 * two Razorpay orders, two `pending` Payment rows, two Donations, or two
 * SevaBookings — and a pending seva/hall booking holds a slot against
 * everybody else until the abandoned-checkout prune sweeps it.
 *
 * resources/js/submit-lock.js stops the second press in the browser and is
 * the layer devotees actually feel. This is the layer that holds when
 * that one cannot run: JavaScript blocked, a form replayed from a second
 * tab, an over-eager mobile keyboard sending two submits.
 *
 * The approach is replay, not rejection. Rejecting the second request
 * would punish the devotee for our slow page — they aborted the request
 * that was going to succeed, so an error is the ONLY thing they would
 * see. Instead the second request waits for the first to finish and is
 * served the first one's rendered checkout page, on the first one's
 * Razorpay order. One order, one charge, and from the devotee's side it
 * simply worked.
 *
 * Scope is deliberately narrow. Only 2xx responses are remembered: a
 * validation bounce or the 80G-PAN redirect carries flashed session state
 * that must not be replayed, and leaving those uncached means a devotee
 * who fixes the problem can resubmit immediately.
 *
 * The window is 30 seconds — comfortably longer than the two or three
 * seconds a fumbled double-press spans, and comfortably shorter than the
 * time it takes a human to actually complete a Razorpay payment and come
 * back. That gap is what stops a genuine second gift of the same amount
 * from being swallowed as a duplicate.
 */
class IdempotentPaymentRequest
{
    /** How long a completed checkout page stays replayable. */
    private const RESULT_TTL = 30;

    /** How long the in-flight marker survives if a request dies hard. */
    private const LOCK_TTL = 60;

    /** Longest a duplicate will wait for the original to finish. */
    private const WAIT_MS = 8000;

    private const POLL_MS = 150;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keyFor($request);

        if ($key === null) {
            return $next($request);
        }

        try {
            $cache = Cache::store();

            if ($replay = $cache->get($key.':res')) {
                return $this->replay($replay);
            }

            // add() is the atomic test-and-set. Losing it means an identical
            // submission is already in flight, so wait for its answer rather
            // than opening a second Razorpay order alongside it.
            if (! $cache->add($key.':lock', 1, self::LOCK_TTL)) {
                $waited = 0;

                while ($waited < self::WAIT_MS) {
                    usleep(self::POLL_MS * 1000);
                    $waited += self::POLL_MS;

                    if ($replay = $cache->get($key.':res')) {
                        return $this->replay($replay);
                    }

                    // The original finished without a replayable result
                    // (validation error, redirect, exception). Fall through
                    // and let this request have its own turn.
                    if (! $cache->has($key.':lock')) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // A cache outage must never stand between a devotee and the
            // donate button. Degrade to exactly the old behaviour.
            Log::warning('Payment idempotency guard unavailable', ['error' => $e->getMessage()]);

            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // A crashed attempt is not an in-flight one. Drop the marker so
            // the devotee's next press is handled immediately instead of
            // sitting through the wait window for a request that is gone.
            $this->release($key);

            throw $e;
        }

        try {
            $cache = Cache::store();

            // Store the result BEFORE dropping the in-flight marker, so a
            // duplicate polling in between never sees "nothing in flight and
            // nothing to replay" and starts a second Razorpay order.
            if ($this->isReplayable($response)) {
                $cache->put($key.':res', [
                    'content' => $response->getContent(),
                    'type' => $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                ], self::RESULT_TTL);
            }

            // The marker's whole job is "someone is working on this right
            // now", and nobody is any more. Leaving it to expire on its own
            // would make a legitimate repeat gift — after the replay window
            // has closed — wait out the poll loop for nothing.
            $cache->forget($key.':lock');
        } catch (\Throwable $e) {
            Log::warning('Payment idempotency guard could not record result', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function release(string $key): void
    {
        try {
            Cache::store()->forget($key.':lock');
        } catch (\Throwable $e) {
            // Nothing to do — the marker expires on its own.
        }
    }

    /**
     * A stable fingerprint of "this devotee submitting this exact form".
     *
     * Anonymous requests get no key: these routes are all behind
     * auth:devotee, so a null user means something is already off and the
     * guard should stay out of the way rather than key everyone together.
     */
    private function keyFor(Request $request): ?string
    {
        if (! $request->isMethod('POST')) {
            return null;
        }

        $devoteeId = Auth::guard('devotee')->id();

        if (! $devoteeId) {
            return null;
        }

        $payload = Arr::except($request->input(), ['_token', '_method']);
        $this->sortDeep($payload);

        // input() excludes uploads, but a different photo IS a different
        // submission — fingerprint the files by name and size so the seva
        // and donation extra-field uploads are not all treated alike.
        $files = [];

        foreach (Arr::dot($request->allFiles()) as $field => $file) {
            if ($file instanceof UploadedFile) {
                $files[$field] = $file->getClientOriginalName().':'.(string) @$file->getSize();
            }
        }

        ksort($files);

        $fingerprint = json_encode([
            'route' => $request->route()?->getName() ?? $request->path(),
            'path' => $request->path(),
            'input' => $payload,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE);

        return 'pay:idem:'.$devoteeId.':'.sha1((string) $fingerprint);
    }

    /**
     * Only a fully rendered checkout page is worth replaying, and only when
     * it is HTML — a streamed or file response cannot be handed out twice.
     */
    private function isReplayable(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (! $response instanceof \Illuminate\Http\Response) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function replay(array $cached): Response
    {
        return response($cached['content'], 200)
            ->header('Content-Type', $cached['type'])
            // Never let a shared cache or the browser keep a checkout page.
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Payment-Replay', '1');
    }

    /**
     * Recursive ksort so ["a" => 1, "b" => 2] and ["b" => 2, "a" => 1]
     * fingerprint identically — browsers do not guarantee field order.
     */
    private function sortDeep(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortDeep($value);
            }
        }

        unset($value);
        ksort($data);
    }
}
