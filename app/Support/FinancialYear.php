<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The Indian financial year label ("2026-27") for a moment in time.
 *
 * Single home for a calculation that had drifted into four copies —
 * CounterEntryService::financialYearFor plus inline expressions in the
 * web and API donation controllers. It stamps `financial_year` on every
 * donation row and, through that, keys the STATUTORY 80G receipt
 * sequence (temple_receipt_sequences PK). Two sites disagreeing about
 * which year a 31-March payment belongs to would split one year's serials
 * across two counters, so this must have exactly one definition.
 *
 * The year turns on 1 April: anything from April onwards opens the year
 * named after it, anything in Jan-Mar closes the year opened the previous
 * April.
 */
final class FinancialYear
{
    public static function for(CarbonInterface $moment): string
    {
        return $moment->month >= 4
            ? $moment->year.'-'.substr((string) ($moment->year + 1), -2)
            : ($moment->year - 1).'-'.substr((string) $moment->year, -2);
    }

    public static function current(): string
    {
        return self::for(now());
    }
}
