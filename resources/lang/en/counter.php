<?php

/*
 * Item 6.1 — manual cash entry.
 *
 * The ADMIN screen itself stays English (project convention: every
 * Filament resource hard-codes its labels). Only strings that end up in
 * front of a DEVOTEE live here — right now that is the shipping address
 * printed on the store invoice PDF for a walk-in counter sale, which
 * GenerateStoreInvoice renders in the devotee's own language.
 */

return [
    'sale_address' => 'Counter sale — collected at the temple',
];
