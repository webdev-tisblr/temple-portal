<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Donation;
use App\Models\SevaBooking;
use App\Services\GreetingCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The devotee's greeting cards, in the app (2026-08-29).
 *
 * A card was previously delivered on WhatsApp and nowhere else, so a Meta
 * rejection, a blocked number or a devotee who simply cleared the chat lost
 * the keepsake for good. This lists every card the devotee is entitled to so
 * the app can show, download and share them on demand.
 *
 * Each row carries two links, because they are used for different things:
 *
 *   • `preview_url` — the permanent PUBLIC web route the WhatsApp message
 *     already points at. It needs no bearer token, so the app can hand it
 *     straight to an image widget, and it is the right thing to share.
 *   • `download_endpoint` — the authenticated API twin, for the app's
 *     download-and-share flow, which carries the token itself and follows the
 *     redirect to R2 by hand.
 *
 * Both regenerate a swept card before serving it, so LISTING renders nothing:
 * a hundred cards must not mean a hundred PNGs.
 */
class GreetingCardController extends BaseApiController
{
    public function __construct(private GreetingCardService $cards) {}

    public function index(Request $request): JsonResponse
    {
        $devotee = $request->user();

        // Donation cards. A donation qualifies when its type or campaign has
        // artwork configured (cardSourceFor), or when a card was already
        // rendered for it — artwork removed after the fact must not hide a
        // card the devotee has already been sent.
        $donations = Donation::query()
            ->where('devotee_id', $devotee->getKey())
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with(['donationType', 'campaign'])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->filter(fn (Donation $d) => $d->greeting_card_path !== null || $this->cards->cardSourceFor($d) !== null)
            ->map(fn (Donation $d): array => [
                'id' => (string) $d->id,
                'type' => 'donation',
                'title' => $d->campaign?->title ?: ($d->donationType?->name ?: __('donation.title')),
                'subtitle' => '₹'.number_format((float) $d->amount, 2),
                'date' => $d->created_at?->toIso8601String(),
                'preview_url' => route('donation.greeting-card', $d->id),
                'download_endpoint' => '/me/greeting-cards/donation/'.$d->id,
            ]);

        // Seva cards. Only confirmed/completed bookings — a card for a booking
        // that was never paid for would be a keepsake of nothing.
        $bookings = SevaBooking::query()
            ->where('devotee_id', $devotee->getKey())
            ->whereIn('status', ['confirmed', 'completed'])
            ->with('seva')
            ->latest('booking_date')
            ->limit(100)
            ->get()
            ->filter(fn (SevaBooking $b) => $b->greeting_card_path !== null
                || ($b->seva?->greeting_card_template && $b->seva?->greeting_card_config))
            ->map(fn (SevaBooking $b): array => [
                'id' => (string) $b->id,
                'type' => 'seva',
                'title' => $b->seva?->name ?: __('seva.title'),
                'subtitle' => $b->booking_date?->format('d M Y') ?? '',
                'date' => ($b->booking_date ?? $b->created_at)?->toIso8601String(),
                'preview_url' => route('seva.greeting-card', $b->id),
                'download_endpoint' => '/me/greeting-cards/seva/'.$b->id,
            ]);

        $cards = $donations->concat($bookings)
            ->sortByDesc('date')
            ->values();

        return $this->success(['cards' => $cards]);
    }

    /**
     * Stream one card, for the app's download-and-share flow.
     *
     * Scoped to the caller's own records — the public web route is guessable
     * only because its ids are UUIDs, and an authenticated endpoint should not
     * inherit that as its security model.
     *
     * Regenerates on miss: r2_private is a short-retention cache and the sweep
     * NULLs the stored path when it deletes an object, so a null path means
     * "rebuild it", not "it never existed".
     */
    public function show(Request $request, string $type, string $id): RedirectResponse|StreamedResponse|JsonResponse
    {
        $devotee = $request->user();

        if ($type === 'seva') {
            $booking = SevaBooking::query()
                ->whereKey($id)
                ->where('devotee_id', $devotee->getKey())
                ->whereIn('status', ['confirmed', 'completed'])
                ->first();

            if ($booking === null) {
                return $this->error(__('seva.card_not_found'), 404);
            }

            if ($this->cards->sevaCardNeedsRegeneration($booking)) {
                $this->cards->generateForSevaBooking($booking);
                $booking->refresh();
            }

            if (blank($booking->greeting_card_path)) {
                return $this->error(__('seva.card_not_found'), 404);
            }

            return private_file_redirect($booking->greeting_card_path, null, inline: true, contentType: 'image/png');
        }

        $donation = Donation::query()
            ->whereKey($id)
            ->where('devotee_id', $devotee->getKey())
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->first();

        if ($donation === null) {
            return $this->error(__('seva.card_not_found'), 404);
        }

        if ($this->cards->needsRegeneration($donation)) {
            $this->cards->generate($donation);
            $donation->refresh();
        }

        if (blank($donation->greeting_card_path)) {
            return $this->error(__('seva.card_not_found'), 404);
        }

        return private_file_redirect($donation->greeting_card_path, null, inline: true, contentType: 'image/png');
    }
}
