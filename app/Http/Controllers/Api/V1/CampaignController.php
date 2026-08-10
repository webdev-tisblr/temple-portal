<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\DonationCampaign;
use App\Support\CampaignDonors;
use App\Support\LocalizedCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campaign endpoints that don't belong in ContentController's catalogue.
 *
 * Additive only: /campaigns and /campaigns/{id} stay exactly where they were
 * (ContentController), so shipped builds — 1.4.8+32 included — are untouched.
 */
class CampaignController extends BaseApiController
{
    /** Short enough that a fresh offering shows up almost immediately. */
    private const CACHE_TTL = 30;

    /**
     * GET /api/v1/campaigns/{campaign}/donors?sort=recent|top&page=n
     *
     * The app mirror of the website's donor list. Same helper, same masking,
     * same captured-only filter as /projects/{slug}/donors — see
     * \App\Support\CampaignDonors.
     *
     * `sort=recent` is individual offerings, newest first, Gupt Daan included
     * but masked. `sort=top` is a different KIND of row: one per donor, with
     * their offerings to this campaign summed into `amount` and counted in
     * `donation_count`, as a single unpaginated page of 10. Gupt Daan is left
     * out of `top` altogether — see App\Support\CampaignDonors::top(). Still
     * not a leaderboard: no rank, no badge, no position.
     *
     * `total` in the meta means "rows in this response" for `top` (i.e. how
     * many donors are listed), as it always did.
     */
    public function donors(DonationCampaign $campaign, Request $request): JsonResponse
    {
        if (! $campaign->is_active) {
            return $this->error('Campaign not found', 404);
        }

        $sort = $request->query('sort') === 'top' ? 'top' : 'recent';

        // The trust can switch a campaign's public donor list off; the web
        // page hides the whole section, so the API must return nothing rather
        // than quietly exposing the rows to the app.
        if (! $campaign->show_donor_list) {
            return $this->success([], 'Success', 200, [
                'sort' => $sort,
                'page' => 1,
                'per_page' => CampaignDonors::PER_PAGE,
                'total' => 0,
                'has_more' => false,
                'enabled' => false,
            ]);
        }

        $page = max(1, (int) $request->query('page', '1'));

        // Rows carry locale-resolved strings (the Gupt Daan mask), so the
        // cache MUST be locale-scoped or the first caller's language would be
        // served to everyone.
        $result = LocalizedCache::remember(
            "campaign_donors.{$campaign->id}.{$sort}.{$page}",
            self::CACHE_TTL,
            function () use ($campaign, $sort, $page): array {
                if ($sort === 'top') {
                    $top = CampaignDonors::top($campaign->id)
                        ->limit(CampaignDonors::PER_PAGE)
                        ->get();

                    return [
                        'data' => CampaignDonors::payload($top),
                        'total' => $top->count(),
                        'has_more' => false,
                    ];
                }

                $donors = CampaignDonors::recent($campaign->id)
                    ->paginate(CampaignDonors::PER_PAGE, ['*'], 'page', $page);

                return [
                    'data' => CampaignDonors::payload($donors->getCollection()),
                    'total' => $donors->total(),
                    'has_more' => $donors->hasMorePages(),
                ];
            }
        );

        return $this->success($result['data'], 'Success', 200, [
            'sort' => $sort,
            'page' => $sort === 'top' ? 1 : $page,
            'per_page' => CampaignDonors::PER_PAGE,
            'total' => $result['total'],
            'has_more' => $result['has_more'],
            'enabled' => true,
        ]);
    }
}
