<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Donation;
use App\Models\SevaBooking;
use RuntimeException;

/**
 * Thrown by ReceiptService when a payment does not qualify for a
 * statutory 80G receipt under the strict PAN rule (item 5.4): no valid PAN
 * on the profile → no Receipt80G row, no receipt number burned, regardless
 * of amount.
 *
 * Covers BOTH sources since 2026-08-31 — direct donations and seva
 * bookings that opted in at booking time. The class name is kept for the
 * call sites and tests that already catch it; read `subjectType` when you
 * need to know which one you are holding.
 *
 * This is deliberately an EXCEPTION rather than a `null` return. There are
 * five places in the codebase that can mint a receipt number, and a silent
 * null is the kind of thing a sixth one forgets to check. Throwing means a
 * new call site has to acknowledge the rule to compile a green test.
 *
 * Carries no PAN — only the reason code. Never put PAN material in an
 * exception message: they end up in logs and error trackers.
 */
class Donation80GNotEligibleException extends RuntimeException
{
    public const REASON_NO_PAN = 'no_pan';

    public const REASON_INVALID_PAN = 'invalid_pan';

    public const REASON_PAN_UNREADABLE = 'pan_unreadable';

    public const REASON_NOT_REQUESTED = 'not_requested';

    public const SUBJECT_DONATION = 'donation';

    public const SUBJECT_SEVA_BOOKING = 'seva_booking';

    public function __construct(
        public readonly string $subjectId,
        public readonly string $reason,
        public readonly string $subjectType = self::SUBJECT_DONATION,
    ) {
        parent::__construct(
            ucfirst(str_replace('_', ' ', $subjectType))
            ." {$subjectId} is not eligible for an 80G receipt ({$reason})."
        );
    }

    public static function for(Donation $donation, string $reason): self
    {
        return new self((string) $donation->id, $reason, self::SUBJECT_DONATION);
    }

    public static function forSevaBooking(SevaBooking $booking, string $reason): self
    {
        return new self((string) $booking->id, $reason, self::SUBJECT_SEVA_BOOKING);
    }

    /**
     * Donor-facing, translated explanation. Used by the app receipt
     * endpoint and the admin panel so a blocked download reads as a rule,
     * not as a crash.
     */
    public function userMessage(): string
    {
        return $this->reason === self::REASON_NOT_REQUESTED
            ? __('donation.no_receipt_not_requested')
            : __('donation.no_receipt_without_pan');
    }
}
