<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Devotee-initiated hall cancellation REQUESTS (2026-08-12).
 *
 * The devotee asks; the trust decides. There is deliberately no path here
 * that cancels a booking outright — a hall cancellation means a refund and a
 * freed date, both of which are the trust's call. Admin approval is the
 * existing status flip in Filament.
 *
 * The booking KEEPS its `confirmed` status while a request is open, so the
 * date stays blocked (see the migration for why this isn't a status value).
 *
 * Single source of truth for "may this be requested" so the API, the website
 * and the admin all answer identically.
 */
class HallCancellationService
{
    public const REASON_NOT_CONFIRMED = 'not_confirmed';
    public const REASON_ALREADY_REQUESTED = 'already_requested';
    public const REASON_ALREADY_STARTED = 'already_started';

    /**
     * Why this booking cannot be put up for cancellation, or NULL when it
     * can. Returns a reason CODE — callers localise it.
     */
    public function ineligibilityReason(HallBooking $booking): ?string
    {
        // Only a live booking can be cancelled. A pending one has not been
        // paid for (it dies on its own), and cancelled/completed are done.
        if ($booking->status !== 'confirmed') {
            return self::REASON_NOT_CONFIRMED;
        }

        if ($booking->cancel_requested_at !== null && $booking->cancel_responded_at === null) {
            return self::REASON_ALREADY_REQUESTED;
        }

        // Once the booking's first day has begun there is nothing left to
        // cancel — at that point it is a refund conversation with the trust,
        // not a self-service request.
        if ($booking->booking_date !== null && $booking->booking_date->startOfDay()->isPast()) {
            return self::REASON_ALREADY_STARTED;
        }

        return null;
    }

    public function canRequest(HallBooking $booking): bool
    {
        return $this->ineligibilityReason($booking) === null;
    }

    /**
     * Record the request and tell the trust.
     *
     * Idempotent under concurrency: the UPDATE is conditional on the request
     * still being open, so a double-tapped button (or a retried request)
     * writes once and notifies once. The notification is dispatched only
     * after the row actually changed, and only after the transaction commits
     * — a rolled-back request must not leave the trust chasing a
     * cancellation that was never recorded.
     */
    public function request(HallBooking $booking, ?string $reason = null): bool
    {
        $reason = $reason !== null ? trim($reason) : null;
        $reason = ($reason === '' ? null : $reason);

        $claimed = DB::transaction(function () use ($booking, $reason): bool {
            $locked = HallBooking::whereKey($booking->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $this->canRequest($locked)) {
                return false;
            }

            $locked->update([
                'cancel_requested_at' => now(),
                'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                // A fresh request re-opens the queue entry even if an earlier
                // one was declined.
                'cancel_responded_at' => null,
            ]);

            $booking->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($claimed) {
            $this->notifyTrust($booking);
        }

        return $claimed;
    }

    /**
     * Fires `hall.booking.cancel_request`. Like every other trigger, nothing
     * is sent unless an admin has created and enabled a template for it —
     * this only offers the event.
     */
    private function notifyTrust(HallBooking $booking): void
    {
        $booking->loadMissing('hall', 'devotee');

        app(NotificationService::class)->dispatch(
            'hall.booking.cancel_request',
            [
                'booking' => array_merge($booking->toArray(), [
                    'booking_number' => app(HallInvoiceService::class)->bookingNumberFor($booking),
                    'booking_date' => $booking->booking_date?->format('d M Y'),
                    'booking_end_date' => $booking->rangeEnd()->format('d M Y'),
                    'booking_date_range' => $booking->date_range_label,
                    'days_count' => (int) ($booking->days_count ?: 1),
                    'total_amount_formatted' => number_format((float) $booking->total_amount, 2),
                    'contact_name' => $booking->contact_name,
                    'contact_phone' => $booking->contact_phone,
                    'cancel_reason' => $booking->cancel_reason ?? '—',
                    'cancel_requested_at' => $booking->cancel_requested_at?->format('d M Y, g:i A'),
                    'hall' => $booking->hall?->toArray(),
                ]),
                'devotee' => $booking->devotee,
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            idempotencyKey: "hall-booking:{$booking->id}:cancel_request:{$booking->cancel_requested_at?->timestamp}",
        );
    }
}
