<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DailyDarshanPhoto;
use App\Models\DonationCampaign;
use App\Models\Seva;
use App\Models\SystemSetting;
use App\Services\DisplayBoardService;
use App\Support\DevoteeLocale;
use App\Support\QrCode;
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

        // App-download QR for the info column. Same source of truth as the
        // login page: the universal (platform-routing) link when the trust has
        // set one, else whichever single store URL exists. Rendered inline by
        // App\Support\QrCode so it regenerates whenever the setting changes —
        // with the exported PNG as the fallback, exactly as on /login.
        $storeUrl = (string) (SystemSetting::getValue('app_universal_store_url', '')
            ?: SystemSetting::getValue('app_android_store_url', '')
            ?: SystemSetting::getValue('app_ios_store_url', ''));

        // Today's darshan, same source of truth as /darshan and the login page.
        // The darshan card was two lines of text in a tall panel; the actual
        // photograph is both the point of the feature and what fills the space.
        $darshan = DailyDarshanPhoto::currentCached();

        // One card per seva, each with its own photograph, rather than a
        // single card listing four names — a picture of the seva is what
        // makes someone in the hall want it.
        $sevaCards = DevoteeLocale::withLocale($this->board->locale(), function (): array {
            return Seva::where('is_active', true)
                ->whereNotNull('image_path')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('category')
                ->map(function ($group) {
                    // Annadaan ships as three menu tiers; they collapse into
                    // ONE card carrying the richest tier's photograph (the
                    // most appealing plate). No price — the board invites
                    // seva, it is not a rate card.
                    $display = $group->sortByDesc('price')->first();

                    return [
                        'title' => $group->count() > 1
                            ? __('board.seva_'.$display->category)
                            : $display->name,
                        'image' => image_url($display->image_path),
                    ];
                })
                ->values()
                ->all();
        });

        // The campaign card carries the campaign's own featured image.
        $campaign = DonationCampaign::where('is_active', true)
            ->whereNotNull('image_path')
            ->orderByDesc('is_featured')
            ->first();

        return $this->noStore(response()->view('pages.board', [
            'token' => $token,
            'demo' => $request->boolean('demo'),
            'darshanUrl' => $darshan?->displayUrl(),
            'sevaCards' => $sevaCards,
            'campaignTitle' => $campaign?->title,
            'campaignImage' => $campaign?->image_path ? image_url($campaign->image_path) : null,
            'darshanCaption' => $darshan?->caption,
            'locale' => $this->board->locale(),
            'pollMs' => 2000,
            'universalStoreUrl' => $storeUrl,
            'androidStoreUrl' => (string) SystemSetting::getValue('app_android_store_url', ''),
            'iosStoreUrl' => (string) SystemSetting::getValue('app_ios_store_url', ''),
            'appQr' => $storeUrl !== '' ? QrCode::cachedSvg($storeUrl) : null,
            'appQrImage' => file_exists(public_path('images/app-qr.png'))
                ? asset('images/app-qr.png')
                : null,
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
