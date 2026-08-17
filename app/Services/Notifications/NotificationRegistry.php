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
 * LANGUAGE: a path naming a `_gu` column is the FALLBACK, not a
 * hardcoding. NotificationContext::getLocalized() prefers the `_hi` /
 * `_en` sibling when the recipient's language is Hindi or English, and
 * only lands on the `_gu` path when that translation is blank. So
 * `{{ seva_name }}` reads Gujarati, Hindi or English to match the body
 * it sits in. There is deliberately ONE token per fact — the `*_hi` /
 * `*_en` twins this list used to offer were a second way to say the same
 * thing and an easy way to pick the wrong one. Templates saved before
 * they were withdrawn keep resolving: the context keys are still
 * published, they are simply no longer offered here.
 *
 * MONEY: amount placeholders render through inr_money() — "₹1,00,000",
 * sign included, Indian digit grouping, paise only when there are paise.
 * A template body must NOT print its own ₹ before one.
 *
 * IMPORTANT: paths must match the SHAPE OF THE ACTUAL DISPATCH
 * CONTEXT. Cross-reference each entry with the dispatch site:
 *   donation.confirmed          → app/Services/PaymentCaptureService.php
 *   donation.campaign.confirmed → app/Services/PaymentCaptureService.php
 *   donation.receipt_80g   → app/Jobs/Generate80GReceipt.php
 *   donation.greeting_card → app/Jobs/GenerateGreetingCard.php
 *   donation.campaign.greeting_card → app/Jobs/GenerateGreetingCard.php
 *   seva.booking.confirmed → app/Jobs/GenerateSevaReceipt.php
 *   hall.booking.confirmed → app/Jobs/GenerateHallInvoice.php
 *   hall.booking.cancel_request → app/Services/HallCancellationService.php
 *   hall.booking.reminder  → app/Console/Commands/DispatchHallReminders.php
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
                'description' => 'Fires when a donation payment is captured. The 80G receipt is built AFTER this trigger — for messages that need receipt_number or receipt_pdf_url, use the donation.receipt_80g trigger instead. NOTE: donations made to a campaign fire donation.campaign.confirmed instead, as soon as at least one template exists for that trigger — the two are mutually exclusive, a donation never fires both.',
                'placeholders' => [
                    'donor_name' => 'Devotee name (devotee.name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'amount' => "Donation amount, ready to print — includes \u{20B9} and Indian digit grouping (amount_formatted)",
                    'donation_type' => "Donation type label in the reader's language (donation.donationType.name_gu)",
                    'purpose' => 'Donation purpose if provided by donor (donation.purpose)',
                    'campaign_title' => "Campaign title if any, in the reader's language (donation.campaign.title_gu)",
                    'date' => 'Donation date (donation.created_at)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Campaign donation confirmation (split off 2026-08-09) ──
            // Same dispatch site as donation.confirmed (PaymentCaptureService,
            // inside the capture transaction) but a SEPARATE key so the trust
            // can write campaign-specific wording. Routing is mutually
            // exclusive: a donation with a campaign fires ONLY this key, a
            // donation without one fires ONLY donation.confirmed. A donor can
            // therefore never receive both messages for one payment.
            //
            // Context adds, on top of donation.confirmed's:
            //   campaign_url, campaign_raised, campaign_goal (computed,
            //   top-level strings), amount_formatted, and the eager-loaded
            //   donation.subCause relation.
            //
            // Localisation note (revised): campaign_title names the _gu
            // column as its FALLBACK — NotificationContext resolves the
            // recipient's language from the dispatch context (or the
            // devotee's saved language) and prefers the matching sibling,
            // so it no longer needs a request to know the locale. That is
            // why there is one campaign_title rather than three.
            'donation.campaign.confirmed' => [
                'label' => 'Donation — campaign payment confirmed',
                'description' => 'Fires when a donation MADE TO A CAMPAIGN is captured, in place of donation.confirmed (never both). Inactive until at least one template exists for this trigger — while none does, campaign donors keep receiving the generic donation.confirmed message. The 80G receipt still follows separately under donation.receipt_80g.',
                'placeholders' => [
                    'donor_name' => 'Devotee name (devotee.name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'amount' => "Donation amount, ready to print — includes \u{20B9} and Indian digit grouping (amount_formatted)",
                    'campaign_title' => "Campaign title in the reader's language (donation.campaign.title_gu)",
                    'sub_cause' => "Sub-cause title if the donor picked one, in the reader's language (donation.subCause.title_gu)",
                    'campaign_url' => 'Public campaign page link, /projects/{slug} (campaign_url)',
                    'campaign_raised' => "Raised so far, ready to print — includes \u{20B9} (campaign_raised)",
                    'campaign_goal' => "Campaign goal, ready to print — includes \u{20B9} (campaign_goal)",
                    'donation_type' => "Donation type label in the reader's language (donation.donationType.name_gu)",
                    'purpose' => 'Donation purpose if provided by donor (donation.purpose)',
                    'date' => 'Donation date (donation.created_at)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee, receipt (array w/ amount_formatted),
            //   donation, name, donor_name, amount, amount_formatted,
            //   receipt_pdf_url, trust_name, _attachments.
            // The greeting card is NO LONGER part of this trigger — it has
            // its own donation.greeting_card trigger (GenerateGreetingCard).
            'donation.receipt_80g' => [
                'label' => 'Donation — 80G receipt ready',
                'description' => 'Fires when the 80G receipt PDF is generated. For WhatsApp, point the Header (DOCUMENT) link at {{ receipt_pdf_url }} to attach the PDF; for email the PDF is already attached automatically.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'donor_name' => 'Devotee name — alias of name (donor_name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'receipt_number' => 'Receipt number (receipt.receipt_number)',
                    'amount' => "Donation amount, ready to print — includes \u{20B9} and Indian digit grouping (amount)",
                    'financial_year' => 'Financial year, eg "2026-27" (receipt.financial_year)',
                    'fiscal_year' => 'Alias of financial_year (receipt.fiscal_year)',
                    'receipt_pdf_url' => 'Presigned 80G PDF URL, 7-day validity (receipt_pdf_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee, donation (Donation w/ devotee, donationType),
            //   name, donor_name, amount, amount_formatted,
            //   greeting_card_url, trust_name, _attachments (the card PNG).
            // Dispatched by GenerateGreetingCard only when the donation type
            // has a card template configured. Which channels actually fire
            // is further gated by the donation type's send_via_email /
            // send_via_whatsapp toggles (greeting_card_config).
            'donation.greeting_card' => [
                'label' => 'Donation — greeting card',
                'description' => 'Fires after a donation is captured when the donation type has a greeting-card template. For WhatsApp, point the Header (IMAGE) link at {{ greeting_card_url }}; for email the PNG is attached automatically. Delivery per channel also respects the donation type\'s send-via toggles.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'donor_name' => 'Devotee name — alias of name (donor_name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'amount' => "Donation amount, ready to print — includes \u{20B9} and Indian digit grouping (amount)",
                    'greeting_card_url' => 'Greeting card image URL — permanent public link (greeting_card_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Seva flow ─────────────────────────────────────────────
            // MERGED trigger (2026-08-04): fires from GenerateSevaReceipt
            // right after payment capture, once the receipt PDF exists —
            // the ONE confirmation message carries the receipt. The old
            // separate `seva.receipt` trigger is retired; its template
            // rows were re-keyed here by migration.
            // Context: booking (SevaBooking->toArray() merged with:
            //   seva_name (gu), seva_name_en, booking_date 'd M Y',
            //   slot_label / slot_time_label / slot_time (all the label),
            //   total_amount_formatted, receipt_number), devotee,
            //   trust_name, receipt_number, receipt_pdf_url (permanent
            //   signed link), _attachments (PDF — absent if render failed).
            'seva.booking.confirmed' => [
                'label' => 'Seva — booking confirmed (with receipt)',
                'description' => 'Fires when a seva booking payment is captured and the receipt PDF is generated — one message carrying the receipt. For WhatsApp, point the Header (DOCUMENT) link at {{ receipt_pdf_url }} (permanent link, regenerates on demand); for email the PDF is attached automatically.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'seva_name' => "Seva name in the reader's language (booking.seva_name)",
                    'booking_date' => 'Seva date, e.g. 28 Jul 2026 (booking.booking_date)',
                    'slot_time' => 'Slot label — reads "Whole day"/"Whole week" for full-day sevas (booking.slot_time_label)',
                    'quantity' => 'Quantity booked (booking.quantity)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (booking.total_amount_formatted)",
                    'receipt_number' => 'Receipt number (receipt_number)',
                    'receipt_pdf_url' => 'Receipt PDF link — permanent, regenerates on demand (receipt_pdf_url)',
                    'booking_reference' => 'Receipt number when the receipt exists, otherwise a short quotable reference — use this, not booking_id (booking.booking_reference)',
                    'booking_id' => 'Internal 36-character UUID. Kept only for templates that already use it; it means nothing to a reader — prefer booking_reference (booking.booking_reference)',
                    'assignee_name' => 'Seva assignee (pujari/staff) name — empty when the seva has no assignee (booking.seva.assignee.name)',
                    'assignee_phone' => 'Seva assignee phone — empty when the seva has no assignee (booking.seva.assignee.phone)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Campaign gifts get their own card message. Shree Ram Vatika
            // should not read like a birthday card, and the platform already
            // makes exactly this split for confirmations
            // (donation.confirmed vs donation.campaign.confirmed).
            //
            // Ships with NO template: nothing is sent until the trust creates
            // and enables one here.
            'donation.campaign.greeting_card' => [
                'label' => 'Donation — campaign greeting card',
                'description' => 'Fires after a donation to a CAMPAIGN is captured, when that campaign has greeting-card artwork uploaded (Campaigns → edit → Greeting Card). For WhatsApp, point the Header (IMAGE) link at {{ greeting_card_url }}; for email the PNG is attached automatically. Normal (non-campaign) donations use "Donation — greeting card" instead.',
                'placeholders' => [
                    'name' => 'Devotee name (name)',
                    'donor_name' => 'Devotee name — alias of name (donor_name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'campaign_title' => 'Campaign name in the devotee\'s language (campaign_title)',
                    'amount' => "Donation amount, ready to print — includes \u{20B9} and Indian digit grouping (amount)",
                    'greeting_card_url' => 'Greeting card image URL — permanent public link (greeting_card_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context: devotee, booking (SevaBooking->toArray() merged with
            //   seva_name (gu), seva_name_en, booking_date 'd/m/Y',
            //   slot_label), name, donor_name (booked-for name), seva_name,
            //   amount, amount_formatted, greeting_card_url, trust_name,
            //   _attachments (the card PNG). Dispatched by
            //   GenerateSevaGreetingCard only when the seva has a card
            //   template configured. The card image itself is rendered in
            //   the devotee's preferred language (per-locale backgrounds).
            'seva.greeting_card' => [
                'label' => 'Seva — greeting card',
                'description' => 'Fires after a seva booking is captured when the seva has a greeting-card template. For WhatsApp, point the Header (IMAGE) link at {{ greeting_card_url }}; for email the PNG is attached automatically.',
                'placeholders' => [
                    'name' => 'Booked-for devotee name (name)',
                    'donor_name' => 'Booked-for devotee name — alias of name (donor_name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'seva_name' => "Seva name in the reader's language (seva_name)",
                    'booking_date' => 'Seva date, e.g. 15/08/2026 (booking.booking_date)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (amount)",
                    'greeting_card_url' => 'Greeting card image URL — permanent public link (greeting_card_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Fires from the seva:dispatch-reminders cron at each
            // reminder_offset configured on the seva. ALL enabled
            // templates for this key fire on every dispatch — admin
            // creates one template per audience (devotee, admin role
            // for pujari/staff/whoever) and each gets the notification.
            //
            // Context note: when a template uses recipient_strategy
            // `admin_role`, NotificationService injects an `admin` key
            // into the context per role-holder so the template can
            // render {{ admin.name }} for each.
            'seva.booking.reminder' => [
                'label' => 'Seva — reminder before booking',
                'description' => 'Fires N hours before each confirmed booking based on the per-seva reminder_offsets list. Every enabled template for this trigger fires (devotee, admin role, etc.).',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    // A staff/pujari reminder names the devotee but had no
                    // way to reach them — assignee_phone below is the STAFF
                    // number, which is not the same thing.
                    'devotee_phone' => 'Devotee phone number — use on staff/pujari reminders so they can reach the person who booked (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'admin_name' => 'Admin name — only when recipient is admin_role (admin.name)',
                    'seva_name' => "Seva name in the reader's language (booking.seva.name_gu)",
                    'booking_date' => 'Booking date (booking.booking_date)',
                    'slot_time' => 'Slot time — reads "Whole day"/"Whole week" for full-day sevas (booking.slot_time_label)',
                    'hours_remaining' => 'Hours remaining until seva, a bare number with no unit (hours_remaining)',
                    'time_remaining_label' => 'Ready-made phrase WITH the unit, in the reader\'s language — "3 કલાક" / "3 घंटे" / "3 hours" (time_remaining_label)',
                    'booking_reference' => 'Receipt number when the receipt exists, otherwise a short quotable reference — use this, not booking_id (booking.booking_reference)',
                    'booking_id' => 'Internal 36-character UUID. Kept only for templates that already use it; it means nothing to a reader — prefer booking_reference (booking.booking_reference)',
                    'assignee_name' => 'Seva assignee (pujari/staff) name — empty when the seva has no assignee (booking.seva.assignee.name)',
                    'assignee_phone' => 'Seva assignee phone — empty when the seva has no assignee (booking.seva.assignee.phone)',
                    // Sevas that offer a product/prasad choice. All three are
                    // empty strings when the seva has no product selection, so
                    // a template can carry them unconditionally.
                    'product_name' => "Chosen product in the reader's language, with the variant appended (\"Chundadi — Large\") — empty when the seva has no product choice (product_name_gu)",
                    'product_price' => 'Price of the chosen product/variant, ready to print — includes ₹ and Indian digit grouping (product_price)',
                    'product_image_url' => 'Chosen product image — public CDN link, usable as a WhatsApp IMAGE header (product_image_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Daily darshan ─────────────────────────────────────────
            // Context: devotee, booking (SevaBooking w/ devotee + seva),
            //   photo (DailyDarshanPhoto), darshan_image_url (absolute
            //   CDN URL string), darshan_date (pre-formatted 'd M Y'),
            //   trust_name. Dispatched per devotee (deduped) by
            //   NotifyBookingDayDevoteesOfDarshanPhoto when the day's
            //   FIRST darshan photo is uploaded, only for sevas with
            //   the "Send daily darshan to booked devotees" toggle on.
            'darshan.photo.uploaded' => [
                'label' => 'Darshan — photo for booking-day devotees',
                'description' => 'Fires when the day\'s first Daily Darshan photo is uploaded, once per devotee holding a confirmed booking for that date on a seva with the darshan toggle enabled. For WhatsApp, point the Header (IMAGE) link at {{ darshan_image_url }} to attach the photo.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'seva_name' => "Seva name in the reader's language (booking.seva.name_gu)",
                    'booking_date' => 'Booking date (booking.booking_date)',
                    'slot_time' => 'Slot time — reads "Whole day" for full-day sevas (booking.slot_time_label)',
                    'darshan_date' => 'Darshan photo date, pre-formatted (darshan_date)',
                    'darshan_image_url' => 'Darshan photo URL — public CDN link (darshan_image_url)',
                    'assignee_name' => 'Seva assignee (pujari/staff) name — empty when the seva has no assignee (booking.seva.assignee.name)',
                    'assignee_phone' => 'Seva assignee phone — empty when the seva has no assignee (booking.seva.assignee.phone)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Hall flow ─────────────────────────────────────────────
            // Context: booking (array of HallBooking->toArray() merged
            //   with: booking_number, booking_type_label, booking_date
            //   formatted, total_amount_formatted, contact_phone,
            //   contact_name, hall (array)), devotee, trust_name,
            //   invoice_pdf_url (permanent signed link), _attachments
            //   (absent if PDF render failed).
            // Hall names ARE translated (temple_halls.name_gu/_hi/_en);
            // the `name` column is the pre-translation legacy value that
            // the model's accessor falls back to.
            'hall.booking.confirmed' => [
                'label' => 'Hall — booking confirmed',
                'description' => 'Fires when a hall booking payment is captured and the invoice PDF is generated. For WhatsApp, point the Header (DOCUMENT) link at {{ invoice_pdf_url }} (permanent link, regenerates on demand); for email the PDF is attached automatically.',
                'placeholders' => [
                    'contact_name' => 'Contact name (booking.contact_name)',
                    'contact_phone' => 'Contact phone (booking.contact_phone)',
                    'devotee_name' => 'Name on the account, which may differ from the contact name (devotee.name)',
                    'devotee_phone' => 'Phone on the account — contact_phone above is the number written on THIS booking (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'hall_name' => "Hall name in the reader's language (booking.hall.name_gu)",
                    // The path MUST be the last thing in the description —
                    // buildPlaceholderMap only reads a trailing (…) group and
                    // silently falls back to the token name otherwise, which
                    // mapped this to a non-existent top-level `booking_date`.
                    'booking_date' => 'Booking start date, pre-formatted; unchanged meaning for single-day bookings (booking.booking_date)',
                    'booking_end_date' => 'Booking end date, pre-formatted — same as the start for a single-day booking (booking.booking_end_date)',
                    'booking_date_range' => 'Whole range as one string, eg "12 – 14 Aug 2026" (booking.booking_date_range)',
                    'days_count' => 'Number of days booked, 1 for a single-day booking (booking.days_count)',
                    'booking_type' => 'Booking type label, eg "Full Day" (booking.booking_type_label)',
                    'purpose' => 'Booking purpose (booking.purpose)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (booking.total_amount_formatted)",
                    'subtotal_amount' => "Taxable value before GST, includes \u{20B9} — blank when the booking carries no GST (booking.subtotal_amount_formatted)",
                    'gst_amount' => "GST charged, includes \u{20B9} — blank when the booking carries no GST (booking.gst_amount_formatted)",
                    'gst_rate' => 'GST rate, eg "18%" — blank when the booking carries no GST (booking.gst_rate_formatted)',
                    'booking_number' => 'Booking number (booking.booking_number)',
                    'invoice_pdf_url' => 'Invoice PDF link — permanent, regenerates on demand (invoice_pdf_url)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Fires when a DEVOTEE asks to cancel a confirmed hall booking.
            // The booking is NOT cancelled by this — it stays confirmed and
            // the date stays blocked until the trust decides in admin. Map
            // this to a WhatsApp template aimed at the trust's own number so
            // somebody actually sees the request; a devotee-facing
            // acknowledgement can be a second template on the same trigger.
            'hall.booking.cancel_request' => [
                'label' => 'Hall — cancellation REQUESTED by devotee',
                'description' => 'Fires when a devotee requests cancellation of a confirmed hall booking from the app or the website. Nothing is cancelled automatically — an admin approves or declines it on the booking page. Point this at the trust so requests are seen promptly.',
                'placeholders' => [
                    'contact_name' => 'Contact name on the booking (booking.contact_name)',
                    'contact_phone' => 'Contact phone on the booking (booking.contact_phone)',
                    'devotee_name' => 'Name on the account that raised the request (devotee.name)',
                    'devotee_phone' => 'Phone on the account — worth including so the trust can call back even when the booking contact is unreachable (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'hall_name' => "Hall name in the reader's language (booking.hall.name_gu)",
                    'booking_number' => 'Booking number (booking.booking_number)',
                    'booking_date' => 'Booking start date, pre-formatted (booking.booking_date)',
                    'booking_date_range' => 'Whole range as one string, eg "12 - 14 Aug 2026" (booking.booking_date_range)',
                    'days_count' => 'Number of days booked (booking.days_count)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (booking.total_amount_formatted)",
                    'cancel_reason' => 'Reason the devotee gave, or an em dash when they gave none (booking.cancel_reason)',
                    'cancel_requested_at' => 'When the request was made, pre-formatted (booking.cancel_requested_at)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // Context is built by DispatchHallReminders, not by a capture
            // path: booking (HallBooking->toArray() merged with hall_name,
            // booking_date, booking_date_range, days_count,
            // total_amount_formatted, booking_number), devotee,
            // time_remaining_label, trust_name.
            'hall.booking.reminder' => [
                'label' => 'Hall — reminder before booking',
                'description' => 'Fires ahead of a confirmed hall booking, at the times configured under Reminders on each hall (Admin → Halls → edit → Reminders). No rules means nothing fires. The reminder is counted back from the start of the FIRST booked day, and a WhatsApp reminder to the hirer goes to the contact number on the booking when there is one, otherwise to their registered number.',
                'placeholders' => [
                    'contact_name' => 'Contact name on the booking (booking.contact_name)',
                    'contact_phone' => 'Contact phone on the booking (booking.contact_phone)',
                    'devotee_name' => 'Devotee name on the account (devotee.name)',
                    'devotee_phone' => 'Phone on the account — contact_phone above is the number written on THIS booking, often a different person (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'admin_name' => 'Admin name — only when the rule targets an admin role (admin.name)',
                    'hall_name' => "Hall name in the reader's language (booking.hall_name)",
                    'booking_number' => 'Booking number (booking.booking_number)',
                    'booking_date' => 'Booking start date, pre-formatted (booking.booking_date)',
                    'booking_date_range' => 'Whole range as one string, eg "12 - 14 Aug 2026" (booking.booking_date_range)',
                    'days_count' => 'Number of days booked (booking.days_count)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (booking.total_amount_formatted)",
                    'hours_remaining' => 'Hours remaining until the booking, a bare number with no unit (hours_remaining)',
                    'time_remaining_label' => 'Ready-made phrase WITH the unit, in the reader\'s language — "3 કલાક" / "3 घंटे" / "3 hours" (time_remaining_label)',
                    'trust_name' => 'Trust name from System Settings (trust_name)',
                ],
            ],

            // ── Store flow ────────────────────────────────────────────
            // Context: devotee, order (array of Order->toArray() merged
            //   with: total_amount_formatted, items_count), trust_name,
            //   invoice_pdf_url (permanent signed link), _attachments
            //   (absent if PDF render failed).
            'store.order.confirmed' => [
                'label' => 'Store — order confirmed',
                'description' => 'Fires when a store order payment is captured and the invoice PDF is generated. For WhatsApp, point the Header (DOCUMENT) link at {{ invoice_pdf_url }} (permanent link, regenerates on demand); for email the PDF is attached automatically.',
                'placeholders' => [
                    'devotee_name' => 'Devotee name (devotee.name)',
                    'devotee_phone' => 'Devotee phone number — useful on a dispatch/packing notification to the trust (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
                    'order_number' => 'Order number (order.order_number)',
                    'amount' => "Total amount, ready to print — includes \u{20B9} and Indian digit grouping (order.total_amount_formatted)",
                    'item_count' => 'Number of PRODUCT LINES, not units — two of one product counts as 1. For "your order of N items" use total_quantity instead (order.items_count)',
                    'total_quantity' => 'Total units ordered — 2 of one product = 2 (order.total_quantity)',
                    'items_list' => 'Each line as "2 × Shri Ladoo Prasad", ONE PER LINE. EMAIL ONLY — WhatsApp rejects a parameter containing newlines; use items_summary there (order.items_list)',
                    'items_summary' => 'Same list on one line: "2 × Shri Ladoo Prasad, 1 × Photo Frame", capped at 4 with "+N more". Safe for WhatsApp (order.items_summary)',
                    'invoice_pdf_url' => 'Invoice PDF link — permanent, regenerates on demand (invoice_pdf_url)',
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
                    'name' => 'Devotee name if registered, blank otherwise (name)',
                ],
            ],

            // ── Admin flow ────────────────────────────────────────────
            // Context: submission (ContactSubmission model), trust_name.
            'contact.submitted' => [
                'label' => 'Contact — form submission',
                'description' => 'Fires when a devotee posts the contact form. Notify the trust admin. Since 2026-08-17 the form requires a login, so name/phone are always the sender\'s real profile details rather than free text.',
                'placeholders' => [
                    'name' => 'Submitter name, from their profile (submission.name)',
                    'phone' => 'Submitter phone, from their profile (submission.phone)',
                    'email' => 'Submitter email — empty when they have not added one (submission.email)',
                    // Pre-resolved by the dispatch site: the enum itself would
                    // render as its raw value ("seva_request") through
                    // NotificationContext::formatForDisplay.
                    'category' => 'What the message is about — "Suggestion", "Complaint", "Question / enquiry" etc (category_label)',
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
                    'devotee_phone' => 'Devotee phone number (devotee.phone)',
                    'devotee_email' => 'Devotee email — empty when they have not added one (devotee.email)',
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
            ->mapWithKeys(fn ($v, $k) => [$k => $v['label']." — {$k}"])
            ->all();
    }

    public static function describe(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
