<?php

return [
    // Badges shown on date / slot chips.
    'not_available' => 'उपलब्ध नहीं',
    'available' => 'उपलब्ध',

    // reason_code → devotee-facing text (App\Enums\UnavailableReason).
    'reason' => [
        'full' => 'पूरी तरह बुक हो चुका है',
        'blackout' => 'इस तारीख को उपलब्ध नहीं',
        'weekday_closed' => 'इस दिन बंद है',
        'outside_period' => 'बुकिंग के लिए खुला नहीं है',
        'no_slots' => 'इस दिन कोई स्लॉट नहीं है',
        'elapsed' => 'यह समय बीत चुका है',
        'cutoff' => 'इस समय के लिए बुकिंग बंद है',
        'hall_booked' => 'पहले से बुक है',
        'range_too_long' => 'बहुत अधिक दिन चुने गए हैं',
        'past_date' => 'यह तारीख बीत चुकी है',
    ],

    // Server-side rejection messages (booking validation).
    'cutoff_blocked' => 'शुरू होने के समय से :hours घंटे पहले बुकिंग बंद हो जाती है। कृपया बाद की तारीख या समय चुनें।',
    'elapsed_blocked' => 'यह समय बीत चुका है। कृपया बाद का स्लॉट चुनें।',
    'hall_range_conflict' => ':dates को हॉल पहले से बुक है।',
    'hall_range_too_long' => 'यह हॉल एक बार में अधिकतम :max दिन के लिए बुक किया जा सकता है।',
    'hall_range_invalid' => 'अंतिम तारीख पहली तारीख या उसके बाद की होनी चाहिए।',
    'hall_taken_race' => 'ये तारीखें अभी किसी और ने बुक कर ली हैं। कृपया दूसरी तारीखें चुनें।',

    // Next-available affordance (item 4.4).
    'next_available' => 'अगली उपलब्ध',
    'next_available_none' => 'अगले एक साल में कोई खाली तारीख नहीं मिली।',
    'searching' => 'खोज रहे हैं…',

    // Multi-day hall booking (item 4.2).
    'days_count' => ':count दिन|:count दिन',
    'select_end_date' => 'एक से अधिक दिन बुक करने के लिए दूसरी तारीख पर टैप करें',
    'clear_range' => 'हटाएँ',
];
