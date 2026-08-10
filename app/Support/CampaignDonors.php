<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Donation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of "who shows up on a campaign's public donor list".
 *
 * Both surfaces read from here — the website
 * (\App\Http\Controllers\Web\ProjectController) and the app API
 * (\App\Http\Controllers\Api\V1\CampaignController) — so Recent and Top,
 * web and app, can never drift apart on either the captured-only filter or
 * the Gupt Daan handling. A second copy of this logic is exactly how an
 * anonymous donor ends up named in one list and masked in another.
 *
 * The two lists answer different questions and are shaped differently:
 * recent() lists individual offerings, top() lists DONORS with their
 * offerings summed. payload() is the only mapper for both.
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
     * The donors who have given the MOST TO THIS CAMPAIGN IN TOTAL — one row
     * per donor, their offerings summed, largest total first.
     *
     * This is not "the largest single offerings" (which is what it used to
     * be, and the bug: a devotee who gave ₹1,000 five times did not appear at
     * all, while a single ₹3,000 gift outranked their ₹5,000). Deliberately
     * still NOT framed as a ranking in the UI — no positions, no badges.
     *
     * Two decisions worth keeping:
     *
     *  • **Gupt Daan is excluded from this list entirely** — the offerings are
     *    neither summed nor shown. Masking a name is not enough once totals
     *    are involved: if an anonymous gift counted toward a NAMED donor's
     *    total, the difference between that total and their visible offerings
     *    in Recent would hand you the secret amount; and a masked aggregate
     *    row is either a fabricated composite donor or a per-donor total that
     *    reveals how many anonymous donors there are and what each gave —
     *    diffable against the dated masked rows in Recent. The offerings still
     *    count toward the campaign's raised amount and donor count; they are
     *    simply not attributable here. Recent is untouched and still shows
     *    them masked, exactly as before.
     *
     *  • **Grouping is on the devotee, never the name.** Two devotees called
     *    "Ramesh Patel" stay two rows; one devotee who donated under a
     *    slightly different display name still merges into one. `devotee_id`
     *    is a UUID and is currently NOT NULL with a foreign key, but the
     *    group key falls back to the donation's own id so that a future
     *    detached/walk-in donation can never collapse every such gift into a
     *    single phantom donor.
     *
     * Every column is aggregated because MySQL runs with ONLY_FULL_GROUP_BY.
     * `anonymous` is carried through as MAX() purely as belt-and-braces: if
     * the exclusion above were ever dropped, a group holding an anonymous
     * offering would still be masked by payload() rather than named.
     */
    public static function top(int $campaignId): EloquentBuilder
    {
        return self::query($campaignId)
            ->where('anonymous', false)
            ->select([
                DB::raw('MIN(temple_donations.id) as id'),
                DB::raw('MIN(temple_donations.devotee_id) as devotee_id'),
                DB::raw('SUM(temple_donations.amount) as total_amount'),
                DB::raw('COUNT(*) as donation_count'),
                DB::raw('MAX(temple_donations.created_at) as created_at'),
                DB::raw('MAX(temple_donations.anonymous) as anonymous'),
            ])
            ->groupBy(DB::raw('COALESCE(temple_donations.devotee_id, temple_donations.id)'))
            ->orderByDesc('total_amount')
            ->orderByDesc('created_at');
    }

    /**
     * The ONLY publicly safe shape for a donor row. Gupt Daan (anonymous)
     * donations render as "રામ ભરોસે" with the city blanked, in every list —
     * the account-deletion path flips `anonymous` on a departed devotee's
     * donations and relies on exactly this, so never widen these fields and
     * never bypass this mapper.
     *
     * Handles both row shapes without changing what any key MEANS, because
     * app build 1.4.8+32 is already in the wild:
     *
     *   • a single donation (Recent) → `amount` is that offering, `date` is
     *     when it was made, `donation_count` is 1;
     *   • an aggregated donor (Top)  → `amount` is the SUM of that donor's
     *     offerings to this campaign, `date` is their most recent one, and
     *     `donation_count` says how many were summed.
     *
     * `donation_count` is the only new key: an older build ignores it and
     * still renders the correct total, because `amount` stayed "the rupee
     * figure this row is about".
     *
     * @param  Collection<int, Donation>|EloquentCollection<int, Donation>  $donations
     * @return array<int, array<string, mixed>>
     */
    public static function payload($donations): array
    {
        return $donations->map(function (Donation $d): array {
            // Aggregated rows carry donation_count + total_amount; a plain
            // donation row carries neither.
            $count = $d->getAttribute('donation_count');
            $isAggregate = $count !== null;

            return [
                'name' => $d->anonymous
                    ? __('projects.gupt_daan_name')
                    : ($d->devotee?->name ?? __('projects.devotee_fallback')),
                'city' => $d->anonymous ? '' : ($d->devotee?->city ?? ''),
                'amount' => (float) ($isAggregate ? $d->getAttribute('total_amount') : $d->amount),
                'date' => $d->created_at?->format('d/m/Y') ?? '',
                'donation_count' => $isAggregate ? (int) $count : 1,
            ];
        })->values()->toArray();
    }
}
