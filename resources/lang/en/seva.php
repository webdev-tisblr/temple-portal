<?php

return [
    // Shown when a devotee opens a card that was never configured for
    // their seva/donation, or that belongs to somebody else.
    'card_not_found' => 'This greeting card is not available.',

    'gallery' => 'Photos & Videos',
    'index_subtitle' => 'Take part in the Dham\'s sevas online and earn punya.',
    'cat_all' => 'All Sevas',
    'cat_shringar' => 'Shringar Seva',
    'cat_vastra' => 'Vastra Seva',
    'cat_annadan' => 'Annadan Seva',
    'cat_puja' => 'Puja Seva',
    'cat_special' => 'Special Sevas',
    'cat_other' => 'Other Sevas',
    'category_count' => ':count Sevas',
    'none_available' => 'No sevas are available right now.',
    'min_amount' => 'Minimum seva donation:',
    'choose_date_time' => 'Select date and time',
    'choose_date' => 'Select date',
    'loading_dates' => 'Loading dates...',
    'no_dates' => 'No available dates in the next 30 days.',
    'loading' => 'Loading...',
    'not_available_date' => 'Seva not available on this date',
    'no_slots' => 'No time slots are configured for this seva.',
    'available_time' => 'Available time',
    'minutes' => 'minutes',
    'name_label' => 'Name for the seva (optional)',
    'name_placeholder' => 'Your or your family\'s name',
    'book_for' => 'Book — ',
    'login_to_book' => 'Login to book',
    'donate_for_seva' => 'Donate for this seva',
    'payment_processing' => 'Payment is processing...',
    'razorpay_opening' => 'Razorpay checkout is opening. Please wait.',
    'booked_title' => 'Seva booked!',
    'booked_sub' => 'Your seva has been booked successfully. The 80G receipt will be sent via email/WhatsApp.',
    'booking_details' => 'Booking Details',
    'devotee_name' => 'Devotee name',
    'selected' => 'Selected',
    'seva_contact' => 'Seva Contact',
    'contact_prompt' => 'Contact us for any questions about the seva.',
    'view_more_seva' => 'View more sevas',
    'payment_success' => 'Payment successful!',
    'payment_success_sub' => 'Your payment was received successfully. The 80G receipt will be sent via email/WhatsApp.',
    'verify_pending' => 'Verification pending',
    'verify_pending_sub' => 'Payment could not be verified. If the payment succeeded, it will appear in your dashboard shortly.',
    'view_dashboard' => 'View Dashboard',
    'payment_failed' => 'Payment failed',
    'payment_failed_sub' => 'The payment was cancelled or failed. Please try again.',
    'book_full_day' => 'Book this whole day',
    'book_full_week' => 'Book this whole week',
    'full_day_booked' => 'This day is fully booked',
    'full_week_booked' => 'This week is fully booked',
    // Shown in place of a clock time for full-day / full-week bookings,
    // whose slot_time holds a sentinel rather than an HH:MM.
    'slot_full_day' => 'Whole day',
    'slot_full_week' => 'Whole week',
    'choose_option' => 'Choose an option',
    'book' => 'Book',
    // Step eyebrow above the product / date pickers when a seva offers both.
    'step' => 'Step :n',
    // Every product linked to this seva is out of stock, so there is nothing
    // to choose and the seva cannot be booked until stock is added back.
    'products_unavailable' => 'All options for this seva are currently out of stock. Please try again later.',
    // Headline price for sevas priced by a linked product. Mirrors the app's
    // seva.from_amount string so both surfaces read identically.
    'from_amount' => 'Starting ₹:amount',
    // ── 80G opt-in on the booking form (2026-08-31) ──────────────────
    // Default UNCHECKED. Ticking it swaps the ordinary seva receipt for
    // the statutory 80G one — a booking gets one receipt, never both.
    // The PAN prompt strings are shared with the donate form
    // (donation.pan_required_title / add_pan_now / continue_without_80g /
    // pan_on_file); only these three are seva-specific, because the
    // donation wording says "donation" where this must say "seva".
    'want_80g' => 'I want an 80G tax-exemption receipt for this seva',
    'want_80g_hint' => 'Requires a valid PAN on your profile. Without a PAN no 80G receipt is issued — you still receive your usual seva receipt.',
    'pan_required_body' => 'Add your PAN to your profile and we will bring you straight back here to finish this booking.',
];
