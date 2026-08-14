<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * inr_money() — the form every notification amount placeholder renders in
 * (2026-08-14).
 *
 * Amounts used to go out as number_format(..., 2): "15,000.00" with Western
 * grouping and no sign, which on a bare WhatsApp parameter is all the
 * devotee sees. The sign now lives in the value, and the grouping is Indian.
 */
class InrMoneyTest extends TestCase
{
    public function test_it_matches_the_requested_shape_at_each_magnitude(): void
    {
        $this->assertSame('₹10', inr_money(10));
        $this->assertSame('₹100', inr_money(100));
        $this->assertSame('₹1,000', inr_money(1000));
        $this->assertSame('₹10,000', inr_money(10000));
        $this->assertSame('₹1,00,000', inr_money(100000));
    }

    public function test_it_groups_in_the_indian_system_above_a_lakh(): void
    {
        // Western grouping would say 1,000,000 / 15,000,000.
        $this->assertSame('₹10,00,000', inr_money(1000000));
        $this->assertSame('₹1,50,00,000', inr_money(15000000));
        $this->assertSame('₹99,999', inr_money(99999));
    }

    public function test_paise_appear_only_when_there_are_paise(): void
    {
        $this->assertSame('₹1,000', inr_money(1000.00));
        $this->assertSame('₹1,000.50', inr_money(1000.50));
        $this->assertSame('₹1,000.05', inr_money(1000.05));

        // A float that rounds to whole rupees must not sprout a ".00".
        $this->assertSame('₹1,000', inr_money(1000.004));
    }

    public function test_it_accepts_the_shapes_eloquent_hands_over(): void
    {
        // Decimal columns arrive as strings; a null amount must not fatal.
        $this->assertSame('₹15,000', inr_money('15000.00'));
        $this->assertSame('₹501', inr_money('501'));
        $this->assertSame('₹0', inr_money(null));
        $this->assertSame('₹0', inr_money(0));
    }

    public function test_the_sign_leads_a_negative(): void
    {
        // "-₹500", never "₹-500".
        $this->assertSame('-₹500', inr_money(-500));
    }

    public function test_inr_itself_still_returns_no_sign(): void
    {
        // Web/PDF callers print their own ₹ and rely on this staying bare.
        $this->assertSame('1,00,000', inr(100000));
    }
}
