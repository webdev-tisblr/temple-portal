<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Donation;
use RuntimeException;

/**
 * Thrown by ReceiptService::generateReceipt() when a donation does not
 * qualify for a statutory 80G receipt under the strict PAN rule
 * (item 5.4): no valid PAN on the donor's profile → no Receipt80G row,
 * no receipt number burned, regardless of amount.
 *
 * This is deliberately an EXCEPTION rather than a `null` return. There are
 * four places in the codebase that can mint a receipt number, and a silent
 * null is the kind of thing a fifth one forgets to check. Throwing means a
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

    public function __construct(
        public readonly string $donationId,
        public readonly string $reason,
    ) {
        parent::__construct("Donation {$donationId} is not eligible for an 80G receipt ({$reason}).");
    }

    public static function for(Donation $donation, string $reason): self
    {
        return new self((string) $donation->id, $reason);
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
