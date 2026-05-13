<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Static catalogue of every notification trigger the platform can fire.
 *
 * Two jobs:
 *   • Power the "Trigger" picker in the Filament admin so admins
 *     pick from a known list instead of typing arbitrary keys.
 *   • Document the placeholders each trigger publishes into the
 *     dispatch context — drives the "Available placeholders" sidebar
 *     when editing a template.
 *
 * When a new domain event needs to send notifications, add an entry
 * here AND fire dispatch() with a context that satisfies the listed
 * placeholders. Phase 4 wires up the listeners that do the firing.
 */
final class NotificationRegistry
{
    /**
     * @return array<string, array{label: string, description: string, placeholders: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            // ── Donation flow ─────────────────────────────────────────
            'donation.confirmed' => [
                'label' => 'Donation — payment confirmed',
                'description' => 'Fired when a devotee donation payment is captured.',
                'placeholders' => [
                    'donor_name' => 'Devotee name (donation.devotee.name)',
                    'amount' => 'Donation amount in INR (donation.amount)',
                    'receipt_number' => 'Receipt number (donation.receipt_number)',
                    'donation_type' => 'Donation type label (donation.donation_type.name)',
                    'campaign_title' => 'Campaign title if any (donation.campaign.title)',
                    'date' => 'Donation date (donation.created_at)',
                ],
            ],
            'donation.receipt_80g' => [
                'label' => 'Donation — 80G receipt ready',
                'description' => 'Fires when an 80G receipt PDF is generated. For WhatsApp, point the Header (DOCUMENT) link to {{ receipt_pdf_url }} to attach the PDF; for email the PDF is already attached automatically.',
                'placeholders' => [
                    'donor_name' => 'Devotee name (donor_name)',
                    'receipt_number' => 'Receipt number (receipt.receipt_number)',
                    'amount' => 'Donation amount in INR (amount)',
                    'amount_formatted' => 'Amount with thousands separator (amount_formatted)',
                    'fiscal_year' => 'Fiscal year (receipt.fiscal_year)',
                    'receipt_pdf_url' => 'Presigned 80G PDF URL, 7-day validity (receipt_pdf_url)',
                    'greeting_card_url' => 'Greeting card download URL — empty when card is disabled (greeting_card_url)',
                ],
            ],

            // ── Seva flow ─────────────────────────────────────────────
            'seva.booking.confirmed' => [
                'label' => 'Seva — booking confirmed',
                'description' => 'Fired when a seva booking payment is captured.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (booking.devotee.name)',
                    'seva_name' => 'Seva name (booking.seva.name)',
                    'booking_date' => 'Booking date (booking.booking_date)',
                    'slot_time' => 'Slot time (booking.slot_time)',
                    'amount' => 'Total amount (booking.total_amount)',
                ],
            ],

            // ── Hall flow ─────────────────────────────────────────────
            'hall.booking.confirmed' => [
                'label' => 'Hall — booking confirmed',
                'description' => 'Fired when a hall booking payment is captured.',
                'placeholders' => [
                    'contact_name' => 'Contact name (booking.contact_name)',
                    'hall_name' => 'Hall name (booking.hall.name)',
                    'booking_date' => 'Booking date (booking.booking_date)',
                    'booking_type' => 'full_day / half_day_morning / half_day_evening',
                    'amount' => 'Total amount (booking.total_amount)',
                    'booking_number' => 'Booking number (booking.booking_number)',
                ],
            ],

            // ── Store flow ────────────────────────────────────────────
            'store.order.confirmed' => [
                'label' => 'Store — order confirmed',
                'description' => 'Fired when a store order payment is captured.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (order.devotee.name)',
                    'order_number' => 'Order number (order.order_number)',
                    'amount' => 'Total amount (order.total_amount)',
                    'item_count' => 'Number of distinct items (order.items_count)',
                ],
            ],

            // ── Auth flow ─────────────────────────────────────────────
            'auth.otp' => [
                'label' => 'Auth — OTP requested',
                'description' => 'Fired synchronously when a devotee requests an OTP. Use a templated WhatsApp message — email is also supported.',
                'placeholders' => [
                    'otp' => 'The 6-digit OTP code',
                    'expires_in_minutes' => 'OTP validity window in minutes',
                ],
            ],

            // ── Admin flow ────────────────────────────────────────────
            'contact.submitted' => [
                'label' => 'Contact — form submission',
                'description' => 'Fired when a visitor posts the contact form. Notify the trust admin.',
                'placeholders' => [
                    'name' => 'Submitter name (submission.name)',
                    'phone' => 'Submitter phone (submission.phone)',
                    'email' => 'Submitter email (submission.email)',
                    'subject' => 'Subject (submission.subject)',
                    'message' => 'Message body (submission.message)',
                ],
            ],

            'devotee.registered' => [
                'label' => 'Devotee — first-time registration',
                'description' => 'Fired the first time a devotee verifies their phone via OTP. Welcome message.',
                'placeholders' => [
                    'name' => 'Devotee name (devotee.name)',
                ],
            ],

            'devotee.birthday' => [
                'label' => 'Devotee — birthday greeting',
                'description' => 'Fired daily by the temple:send-birthday-blessings cron for every devotee whose date_of_birth is today.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'language' => "Devotee's preferred language code: gu / hi / en (language)",
                ],
            ],
        ];
    }

    /** Convenience: option list for the Filament Select. */
    public static function asOptions(): array
    {
        return collect(self::all())
            ->mapWithKeys(fn ($v, $k) => [$k => $v['label'] . " — {$k}"])
            ->all();
    }

    public static function describe(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
