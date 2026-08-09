<?php

return [
    // Badges shown on date / slot chips.
    'not_available' => 'Not Available',
    'available' => 'Available',

    // reason_code → devotee-facing text (App\Enums\UnavailableReason).
    'reason' => [
        'full' => 'Fully booked',
        'blackout' => 'Not available on this date',
        'weekday_closed' => 'Closed on this day',
        'outside_period' => 'Not open for booking',
        'no_slots' => 'No slots on this day',
        'elapsed' => 'This time has already passed',
        'cutoff' => 'Booking is closed for this time',
        'hall_booked' => 'Already booked',
        'range_too_long' => 'Too many days selected',
        'past_date' => 'This date has passed',
    ],

    // Server-side rejection messages (booking validation).
    'cutoff_blocked' => 'Bookings close :hours hour(s) before the start time. Please choose a later date or time.',
    'elapsed_blocked' => 'This time has already passed. Please choose a later slot.',
    'hall_range_conflict' => 'The hall is already booked on :dates.',
    'hall_range_too_long' => 'This hall can be booked for a maximum of :max day(s) at a time.',
    'hall_range_invalid' => 'The end date must be on or after the start date.',
    'hall_taken_race' => 'These dates were just booked by someone else. Please choose another range.',

    // Next-available affordance (item 4.4).
    'next_available' => 'Next available',
    'next_available_none' => 'No open date found in the next year.',
    'searching' => 'Searching…',

    // Multi-day hall booking (item 4.2).
    'days_count' => ':count day|:count days',
    'select_end_date' => 'Tap another date to book multiple days',
    'clear_range' => 'Clear',
];
