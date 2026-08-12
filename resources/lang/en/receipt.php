<?php

declare(strict_types=1);

/*
 * Labels for the generated PDF documents (Seva receipt, Hall invoice,
 * Store invoice).
 *
 * NOT used by resources/views/receipts/receipt-80g.blade.php — the 80G
 * receipt is deliberately English-only: it is a statutory document that
 * any assessing officer must be able to read. Nor by
 * invoices/packing-slip.blade.php, which is an internal warehouse sheet.
 *
 * The rendering locale comes from `temple_devotees.language` and is set by
 * the service via App\Support\DevoteeLocale::withLocale() — never from the
 * request, because queued jobs generate most of these PDFs.
 */

return [
    // Document titles
    'title_seva' => 'Seva Booking Receipt',
    'title_hall' => 'Hall Booking Receipt',
    'title_store' => 'Tax Invoice',

    // Trust header registration line
    'label_trust_reg' => 'Trust Reg. No',
    'label_80g_reg' => '80G Reg. No',
    'label_trust_pan' => 'PAN',

    // Meta bar
    'receipt_no' => 'Receipt No.',
    'order_no' => 'Order Number',
    'seva_date' => 'Seva Date',
    'booking_date' => 'Booking Date',
    'date' => 'Date',
    'status' => 'Status',
    'booking_type' => 'Booking Type',
    'payment_mode' => 'Payment Mode',

    // Section headings
    'section_seva' => 'Seva Details',
    'section_devotee' => 'Devotee Details',
    'section_hall' => 'Hall Details',
    'section_booking' => 'Booking Details',
    'section_customer' => 'Customer Details',
    'section_items' => 'Order Items',

    // Field labels
    'label_seva' => 'Seva',
    'label_slot' => 'Slot',
    'label_quantity' => 'Quantity',
    'label_selected_item' => 'Selected Item',
    'label_name' => 'Name',
    'label_phone' => 'Phone',
    'label_address' => 'Address',
    'label_seva_in_name_of' => 'Seva in the name of',
    'label_sankalp' => 'Sankalp',
    'label_hall_name' => 'Hall Name',
    'label_capacity' => 'Capacity',
    'label_contact_name' => 'Contact Name',
    'label_purpose' => 'Purpose',
    'label_expected_guests' => 'Expected Guests',
    'persons' => 'persons',
    'devotee_fallback' => 'Devotee',

    // Items table
    'col_sno' => 'S.No.',
    'col_product' => 'Product',
    'col_qty' => 'Qty',
    'col_unit_price' => 'Unit Price',
    'col_subtotal' => 'Subtotal',
    'label_subtotal' => 'Subtotal',
    'label_shipping' => 'Shipping',
    'label_grand_total' => 'Grand Total',

    // The words line itself stays English in every language — see the note
    // in NumberToWords / the services. Only this label is translated.
    'label_amount_in_words' => 'Amount in words',
    'label_taxable_value' => 'Taxable value',
    'label_cgst' => 'CGST',
    'label_sgst' => 'SGST',

    // Statuses & booking types
    'status_pending' => 'Pending',
    'status_confirmed' => 'Confirmed',
    'status_completed' => 'Completed',
    'status_cancelled' => 'Cancelled',
    'type_full_day' => 'Full Day',
    'type_half_day_morning' => 'Half Day (AM)',
    'type_half_day_evening' => 'Half Day (PM)',
    'mode_online' => 'Online',

    // Footer
    'authorised_signatory' => 'Authorised Signatory',
    'computer_generated_receipt' => 'This is a computer-generated receipt and does not require a physical signature.',
    'computer_generated_invoice' => 'This is a computer-generated invoice and does not require a physical signature.',
];
