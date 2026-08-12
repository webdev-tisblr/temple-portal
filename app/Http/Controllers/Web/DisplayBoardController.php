<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\DisplayBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The live donor display board — kiosk page and its polling feed.
 *
 * Both endpoints are token-gated and must never be cached by anything. The
 * page lives on a plain web route (it is HTML), which is why 'board' had to be
 * added to ComingSoonMode::BYPASS_PATHS; the feed lives under /api/v1 where
 * that bypass already applies wholesale.
 */
class DisplayBoardController extends Controller
{
    public function __construct(
        private readonly DisplayBoardService $board,
    ) {}

    /**
     * The screen itself. Token in the query string, because a kiosk browser
     * cannot send a custom header on a top-level navigation.
     */
    public function show(Request $request): View|Response
    {
        $token = (string) $request->query('token', '');

        // 404, not 401 — an unauthenticated probe should not learn that a
        // donor board exists here at all.
        abort_unless($this->board->tokenMatches($token), 404);

        return $this->noStore(response()->view('pages.board', [
            'token' => $token,
            'demo' => $request->boolean('demo'),
            'locale' => $this->board->locale(),
            'pollMs' => 2000,
        ]));
    }

    /**
     * Polling feed. Accepts the token as a header (normal case) or a query
     * param (so the page can be opened and tested by hand).
     */
    public function feed(Request $request): JsonResponse|Response
    {
        $token = (string) ($request->header('X-Board-Token') ?: $request->query('token', ''));

        abort_unless($this->board->tokenMatches($token), 404);

        // Absent `since` is meaningfully different from `since=0`: absent means
        // COLD START (announce nothing, just seed the cursor), whereas 0 means
        // "I am at the beginning, give me everything from the top".
        $since = $request->has('since') ? (int) $request->query('since') : null;
        $limit = (int) $request->query('limit', 10);

        return $this->noStore(response()->json(
            $this->board->feed($since, $limit)
        ));
    }

    /**
     * Belt and braces against both cache layers.
     *
     * CacheGuestResponse only caches six specific path shapes and /board is
     * not one of them, and the Cloudflare rule mirrors those — but a board
     * that goes stale is a board showing yesterday's donors, so both layers
     * are refused explicitly. CDN-Cache-Control is the one Cloudflare obeys.
     */
    private function noStore(JsonResponse|Response $response): JsonResponse|Response
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
