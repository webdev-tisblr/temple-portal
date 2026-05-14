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
 *     dispatch context — drives the "Available placeholders" panel
 *     when editing a template and the auto-fill of `placeholder_map`.
 *
 * Each placeholder description ends with `(<dot.path>)` — the parser
 * (EditNotificationTemplate::buildPlaceholderMap) reads only that
 * trailing parenthesised group and treats the rest as human text.
 * If the path is omitted, the token name itself is used (works for
 * top-level context keys like `trust_name`).
 *
 * IMPORTANT: paths must match the SHAPE OF THE ACTUAL DISPATCH
 * CONTEXT. Cross-reference each entry with the dispatch site:
 *   donation.confirmed     → app/Services/PaymentCaptureService.php
 *   donation.receipt_80g   → app/Jobs/Generate80GReceipt.php
 *   seva.booking.confirmed → app/Services/PaymentCaptureService.php
 *   hall.booking.confirmed → app/Http/Controllers/Web/HallBookingController.php
 *   store.order.confirmed  → app/Jobs/GenerateStoreInvoice.php
 *   auth.otp               → app/Services/OtpService.php
 *   contact.submitted      → app/Http/Controllers/Web/ContactController.php
 *                            + app/Http/Controllers/Api/V1/ContentController.php
 *   devotee.registered     → app/Http/Controllers/{Web,Api/V1}/Auth*Controller.php
 *   devotee.birthday       → app/Console/Commands/SendBirthdayBlessings.php
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
            // Context: donation (Donation model w/ devotee, campaign,
            //   donationType eager-loaded), devotee (Devotee model),
            //   trust_name (string).
            'donation.confirmed' => [
                'label' => 'Donation — payment confirmed',
                'description' => 'Fires when a donation payment is captured. The 80G receipt is built AFTER this trigger — for messages that need receipt_number or receipt_pdf_url, use the donation.receipt_80g trigger instead.',
                'placeholders' => [
                    'donor_name' => 'Devotee name (devotee.name)',
                    'amount' => 'Donation amount in INR (donation.amount)',
                    'donation_type' => 'Donation type label, Gujarati (donation.donationType.name_gu)',
                    'donation_type_en' => 'Donation type label, English (donation.donationType.name_en)',
                    'purpose' => 'Donation purpose if provided by donor (donation.purpose)',
                    'campaign_title' => 'Campaign title if any (donation.campaign.title_gu)',
                    'date' => 'Donation date (donation.created_at)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee, receipt (array w/ amount_formatted),
            //   donation, donor_name, amount, amount_formatted,
            //   receipt_pdf_url, greeting_card_url, trust_name,
            //   _attachments.
            'donation.receipt_80g' => [
                'label' => 'Donation — 80G receipt ready',
                'description' => 'Fires when the 80G receipt PDF is generated. For WhatsApp, point the Header (DOCUMENT) link at {{ receipt_pdf_url }} to attach the PDF; for email the PDF is already attached automatically.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'donor_name' => 'Devotee name — alias of name (donor_name)',
                    'receipt_number' => 'Receipt number (receipt.receipt_number)',
                    'amount' => 'Donation amount in INR (amount)',
                    'amount_formatted' => 'Amount with thousands separator (amount_formatted)',
                    'financial_year' => 'Financial year, eg "2026-27" (receipt.financial_year)',
                    'fiscal_year' => 'Alias of financial_year (receipt.fiscal_year)',
                    'receipt_pdf_url' => 'Presigned 80G PDF URL, 7-day validity (receipt_pdf_url)',
                    'greeting_card_url' => 'Greeting card download URL — empty when card disabled (greeting_card_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Seva flow ─────────────────────────────────────────────
            // Context: booking (SevaBooking w/ devotee + seva loaded),
            //   devotee, trust_name.
            // Seva model exposes name_gu/hi/en — no plain `name` column.
            'seva.booking.confirmed' => [
                'label' => 'Seva — booking confirmed',
                'description' => 'Fires when a seva booking payment is captured.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    'seva_name' => 'Seva name in Gujarati (booking.seva.name_gu)',
                    'booking_date' => 'Booking date (booking.booking_date)',
                    'slot_time' => 'Slot time (booking.slot_time)',
                    'amount' => 'Total amount (booking.total_amount)',
                    'booking_id' => 'Booking ID (booking.id)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Hall flow ─────────────────────────────────────────────
            // Context: booking (array of HallBooking->toArray() merged
            //   with: booking_number, booking_type_label, booking_date
            //   formatted, total_amount_formatted, contact_email,
            //   contact_phone, contact_name, hall (array)),
            //   devotee, trust_name, _attachments.
            // Hall.name (not localized).
            'hall.booking.confirmed' => [
                'label' => 'Hall — booking confirmed',
                'description' => 'Fires when a hall booking payment is captured.',
                'placeholders' => [
                    'contact_name' => 'Contact name (booking.contact_name)',
                    'contact_email' => 'Contact email (booking.contact_email)',
                    'contact_phone' => 'Contact phone (booking.contact_phone)',
                    'hall_name' => 'Hall name (booking.hall.name)',
                    'booking_date' => 'Booking date, pre-formatted (booking.booking_date)',
                    'booking_type' => 'Booking type label, eg "Full Day" (booking.booking_type_label)',
                    'purpose' => 'Booking purpose (booking.purpose)',
                    'amount' => 'Total amount with thousands separator (booking.total_amount_formatted)',
                    'booking_number' => 'Booking number (booking.booking_number)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Store flow ────────────────────────────────────────────
            // Context: devotee, order (array of Order->toArray() merged
            //   with: total_amount_formatted, items_count),
            //   trust_name, _attachments.
            'store.order.confirmed' => [
                'label' => 'Store — order confirmed',
                'description' => 'Fires when a store order payment is captured.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    'order_number' => 'Order number (order.order_number)',
                    'amount' => 'Total amount with thousands separator (order.total_amount_formatted)',
                    'item_count' => 'Number of distinct items (order.items_count)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Auth flow ─────────────────────────────────────────────
            // Context: phone, otp, expires_in_minutes, devotee (or null),
            //   email (or null), name (or null).
            'auth.otp' => [
                'label' => 'Auth — OTP requested',
                'description' => 'Fires synchronously when a devotee requests an OTP. For WhatsApp use recipient = "Look up from event data" with value "phone". For email use the "Devotee in the event" strategy — works once the devotee has registered an email; first-time logins silently skip.',
                'placeholders' => [
                    'otp' => 'The 6-digit OTP code (otp)',
                    'expires_in_minutes' => 'OTP validity window in minutes (expires_in_minutes)',
                    'phone' => 'The phone number requesting OTP (phone)',
                    'name' => "Devotee name if registered, blank otherwise (name)",
                ],
            ],

            // ── Admin flow ────────────────────────────────────────────
            // Context: submission (ContactSubmission model), trust_name.
            'contact.submitted' => [
                'label' => 'Contact — form submission',
                'description' => 'Fires when a visitor posts the contact form. Notify the trust admin.',
                'placeholders' => [
                    'name' => 'Submitter name (submission.name)',
                    'phone' => 'Submitter phone (submission.phone)',
                    'email' => 'Submitter email (submission.email)',
                    'subject' => 'Subject (submission.subject)',
                    'message' => 'Message body (submission.message)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee (Devotee model).
            'devotee.registered' => [
                'label' => 'Devotee — first-time registration',
                'description' => 'Fires the first time a devotee verifies their phone via OTP.',
                'placeholders' => [
                    'name' => 'Devotee name (devotee.name)',
                    'phone' => 'Devotee phone (devotee.phone)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee (Devotee model), name (string),
            //   language (gu/hi/en).
            'devotee.birthday' => [
                'label' => 'Devotee — birthday greeting',
                'description' => 'Fires daily by the temple:send-birthday-blessings cron for every devotee whose date_of_birth is today.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'language' => "Devotee's preferred language code: gu / hi / en (language)",
                    'trust_name' => 'Trust name from System Settings (trust_name)',
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
