<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Donation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The single definition of "who shows up on a campaign's public donor list".
 *
 * Both surfaces read from here — the website
 * (\App\Http\Controllers\Web\ProjectController) and the app API
 * (\App\Http\Controllers\Api\V1\CampaignController) — so Recent and Top,
 * web and app, can never drift apart on either the captured-only filter or
 * the Gupt Daan masking. A second copy of this logic is exactly how an
 * anonymous donor ends up named in one list and masked in another.
 */
final class CampaignDonors
{
    /** Donors per page — and the size of the "Top donors" list. */
    public const PER_PAGE = 10;

    /**
     * Base query behind every public donor list: this campaign's donations
     * whose payment actually reached `captured`, with just the two devotee
     * columns the masked payload is allowed to expose. Pending, failed and
     * abandoned payments never surface.
     */
    public static function query(int $campaignId): EloquentBuilder
    {
        return Donation::where('campaign_id', $campaignId)
            ->whereHas('payment', fn (EloquentBuilder $query) => $query->where('status', 'captured'))
            ->with('devotee:id,name,city');
    }

    /** Newest first — the default list on both surfaces. */
    public static function recent(int $campaignId): EloquentBuilder
    {
        return self::query($campaignId)->orderByDesc('created_at');
    }

    /**
     * The largest offerings first. Deliberately NOT framed as a ranking
     * anywhere in the UI — it is just a different ordering of the same
     * masked rows.
     */
    public static function top(int $campaignId): EloquentBuilder
    {
        return self::query($campaignId)
            ->orderByDesc('amount')
            ->orderByDesc('created_at');
    }

    /**
     * The ONLY publicly safe shape for a donor row. Gupt Daan (anonymous)
     * donations render as "રામ ભરોસે" with the city blanked, in every list —
     * the account-deletion path flips `anonymous` on a departed devotee's
     * donations and relies on exactly this, so never widen these fields and
     * never bypass this mapper.
     *
     * @param  Collection<int, Donation>|EloquentCollection<int, Donation>  $donations
     * @return array<int, array<string, mixed>>
     */
    public static function payload($donations): array
    {
        return $donations->map(fn (Donation $d) => [
            'name' => $d->anonymous
                ? __('projects.gupt_daan_name')
                : ($d->devotee?->name ?? __('projects.devotee_fallback')),
            'city' => $d->anonymous ? '' : ($d->devotee?->city ?? ''),
            'amount' => (float) $d->amount,
            'date' => $d->created_at->format('d/m/Y'),
        ])->values()->toArray();
    }
}
