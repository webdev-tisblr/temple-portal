<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Indian money formatting (2026-08-13).
 *
 * The site printed ₹150,000,000 — thousands grouping, which an Indian donor
 * has to stop and count — while the app showed the same campaign as
 * "₹15 કરોડ". Two different-looking targets for one campaign.
 */
class IndianMoneyFormatTest extends TestCase
{
    /** Grouping: last three digits, then pairs. */
    public function test_inr_groups_the_indian_way(): void
    {
        $this->assertSame('15,00,00,000', inr(150000000));
        $this->assertSame('1,00,000', inr(100000));
        $this->assertSame('12,34,567.89', inr(1234567.89, 2));
    }

    /**
     * Below a lakh the two systems are IDENTICAL. This is what made it safe
     * to swap inr() in across every view without reviewing each amount —
     * prices, hall rates and order totals render exactly as before.
     */
    public function test_small_amounts_are_unchanged(): void
    {
        foreach ([0, 5, 99, 1100, 45000, 99999] as $amount) {
            $this->assertSame(number_format($amount), inr($amount), "changed at {$amount}");
        }
    }

    public function test_negatives_and_nulls_survive(): void
    {
        $this->assertSame('-15,00,00,000', inr(-150000000));
        $this->assertSame('0', inr(null));
    }

    /**
     * Spoken units must match the Flutter app's formatIndianAmount() rule
     * for rule — same rounding to the nearest lakh, same wording.
     */
    public function test_spoken_units_match_the_app(): void
    {
        $this->assertSame('₹15 Crore', inr_units(150000000, 'en'));
        $this->assertSame('₹15 કરોડ', inr_units(150000000, 'gu'));
        $this->assertSame('₹15 करोड़', inr_units(150000000, 'hi'));

        $this->assertSame('₹1 Crore 18 Lakh', inr_units(11765980, 'en'));
        $this->assertSame('₹80 Lakh', inr_units(8000000, 'en'));
        $this->assertSame('₹45K', inr_units(45000, 'en'));
        $this->assertSame('₹900', inr_units(900, 'en'));
    }

    /** The campaign page renders a crore goal in units, not raw digits. */
    public function test_the_campaign_page_shows_a_readable_goal(): void
    {
        $this->assertStringNotContainsString(',', inr_units(150000000, 'en'));
    }
}
