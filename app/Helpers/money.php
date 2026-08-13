<?php

declare(strict_types=1);

if (! function_exists('inr')) {
    /**
     * Format an amount with INDIAN digit grouping.
     *
     * PHP's number_format() groups in thousands — 150000000 becomes
     * "150,000,000", which an Indian donor has to stop and count. The Indian
     * system groups the last three digits and then in pairs:
     * "15,00,00,000" reads immediately as fifteen crore.
     *
     * Below ₹1,00,000 the two systems are IDENTICAL, which is why this can
     * be swapped in everywhere without reviewing each amount: seva prices,
     * hall rates and order totals render exactly as before. Only campaign
     * goals and other large figures visibly change.
     *
     * The rupee sign is NOT included — callers already print ₹ (or an
     * &#8377; entity in the PDFs) and often style it separately.
     */
    function inr(float|int|string|null $amount, int $decimals = 0): string
    {
        $amount = (float) ($amount ?? 0);
        $negative = $amount < 0;
        $amount = abs($amount);

        $formatted = number_format($amount, $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $formatted), 2, null);

        // Last three digits stay together; everything before them is grouped
        // in pairs, right to left.
        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $tail = substr($whole, -3);
            $head = strrev(implode(',', str_split(strrev($head), 2)));
            $whole = $head.','.$tail;
        }

        return ($negative ? '-' : '').$whole.($fraction !== null ? '.'.$fraction : '');
    }
}

if (! function_exists('inr_short')) {
    /**
     * A compact Indian amount for tight spaces — "15 Cr", "2.5 L", "45,000".
     *
     * Only used where the exact paisa does not help the reader (a progress
     * bar's target, a headline figure). Anything a donor is about to PAY
     * must use inr() and show the real number.
     */
    function inr_short(float|int|string|null $amount): string
    {
        $amount = (float) ($amount ?? 0);
        $abs = abs($amount);
        $sign = $amount < 0 ? '-' : '';

        // Trim a trailing ".0" so 15.0 Cr reads as 15 Cr.
        $trim = static fn (float $n): string => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        return match (true) {
            $abs >= 10000000 => $sign.$trim($abs / 10000000).' Cr',
            $abs >= 100000 => $sign.$trim($abs / 100000).' L',
            default => $sign.inr($abs),
        };
    }
}

if (! function_exists('inr_units')) {
    /**
     * Friendly Indian-unit money — "₹15 કરોડ", "₹1 કરોડ 17 લાખ", "₹80 લાખ".
     *
     * A PHP twin of the Flutter app's formatIndianAmount() (see
     * temple_app/lib/core/utils/indian_amount.dart), deliberately matching
     * it rule for rule: values from ₹1 lakh up are rounded to the NEAREST
     * LAKH and spoken in units, because "₹1,17,65,980" on a campaign card
     * reads as accounting rather than devotion — and because a devotee
     * seeing the same campaign in the app and on the site must not be shown
     * two different-looking targets.
     *
     * Includes the ₹ sign, unlike inr(). For anything a donor is about to
     * PAY, use inr() and show the exact figure.
     */
    function inr_units(float|int|string|null $amount, ?string $locale = null): string
    {
        $amount = (float) ($amount ?? 0);
        $locale ??= app()->getLocale();

        [$lakhWord, $croreWord] = match ($locale) {
            'hi' => ['लाख', 'करोड़'],
            'en' => ['Lakh', 'Crore'],
            default => ['લાખ', 'કરોડ'],
        };

        if ($amount >= 100000) {
            $totalLakhs = (int) round($amount / 100000);
            $crores = intdiv($totalLakhs, 100);
            $lakhs = $totalLakhs % 100;

            if ($crores > 0 && $lakhs > 0) {
                return "₹{$crores} {$croreWord} {$lakhs} {$lakhWord}";
            }
            if ($crores > 0) {
                return "₹{$crores} {$croreWord}";
            }

            return "₹{$lakhs} {$lakhWord}";
        }

        if ($amount >= 1000) {
            $thousands = $amount / 1000;
            $s = floor($thousands) == $thousands
                ? number_format($thousands, 0, '.', '')
                : number_format($thousands, 1, '.', '');

            return "₹{$s}K";
        }

        return '₹'.number_format($amount, 0, '.', '');
    }
}
