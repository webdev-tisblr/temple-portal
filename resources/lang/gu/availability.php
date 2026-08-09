<?php

return [
    // Badges shown on date / slot chips.
    'not_available' => 'ઉપલબ્ધ નથી',
    'available' => 'ઉપલબ્ધ',

    // reason_code → devotee-facing text (App\Enums\UnavailableReason).
    'reason' => [
        'full' => 'સંપૂર્ણ બુક થઈ ગયું છે',
        'blackout' => 'આ તારીખે ઉપલબ્ધ નથી',
        'weekday_closed' => 'આ વારે બંધ છે',
        'outside_period' => 'બુકિંગ માટે ખુલ્લું નથી',
        'no_slots' => 'આ દિવસે કોઈ સ્લોટ નથી',
        'elapsed' => 'આ સમય વીતી ગયો છે',
        'cutoff' => 'આ સમય માટે બુકિંગ બંધ છે',
        'hall_booked' => 'પહેલેથી બુક છે',
        'range_too_long' => 'ખૂબ વધારે દિવસ પસંદ કર્યા છે',
        'past_date' => 'આ તારીખ વીતી ગઈ છે',
    ],

    // Server-side rejection messages (booking validation).
    'cutoff_blocked' => 'શરૂઆતના સમયના :hours કલાક પહેલાં બુકિંગ બંધ થઈ જાય છે. કૃપા કરી પછીની તારીખ કે સમય પસંદ કરો.',
    'elapsed_blocked' => 'આ સમય વીતી ગયો છે. કૃપા કરી પછીનો સ્લોટ પસંદ કરો.',
    'hall_range_conflict' => ':dates તારીખે હૉલ પહેલેથી બુક છે.',
    'hall_range_too_long' => 'આ હૉલ એક સાથે વધુમાં વધુ :max દિવસ માટે બુક કરી શકાય છે.',
    'hall_range_invalid' => 'છેલ્લી તારીખ પહેલી તારીખ કે તેના પછીની હોવી જોઈએ.',
    'hall_taken_race' => 'આ તારીખો હમણાં જ કોઈ બીજાએ બુક કરી લીધી છે. કૃપા કરી બીજી તારીખ પસંદ કરો.',

    // Next-available affordance (item 4.4).
    'next_available' => 'આગામી ઉપલબ્ધ',
    'next_available_none' => 'આવતા એક વર્ષમાં કોઈ ખાલી તારીખ મળી નથી.',
    'searching' => 'શોધી રહ્યા છીએ…',

    // Multi-day hall booking (item 4.2).
    'days_count' => ':count દિવસ|:count દિવસ',
    'select_end_date' => 'એકથી વધુ દિવસ બુક કરવા બીજી તારીખ પર ટૅપ કરો',
    'clear_range' => 'રદ કરો',
];
