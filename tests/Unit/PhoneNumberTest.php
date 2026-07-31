<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_indian_inputs_normalize_to_bare_ten_digits(): void
    {
        $this->assertSame('9876543210', PhoneNumber::normalize('9876543210'));
        $this->assertSame('9876543210', PhoneNumber::normalize('+91 98765 43210'));
        $this->assertSame('9876543210', PhoneNumber::normalize('919876543210'));
        $this->assertSame('9876543210', PhoneNumber::normalize('0091-9876543210'));
    }

    public function test_international_inputs_keep_country_code(): void
    {
        $this->assertSame('447911123456', PhoneNumber::normalize('+44 7911 123456'));
        $this->assertSame('447911123456', PhoneNumber::normalize('00447911123456'));
        $this->assertSame('15551234567', PhoneNumber::normalize('+1 (555) 123-4567'));
        $this->assertSame('971501234567', PhoneNumber::normalize('+971 50 123 4567'));
    }

    public function test_invalid_inputs_return_null(): void
    {
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('abc'));
        $this->assertNull(PhoneNumber::normalize('12345'));            // too short
        $this->assertNull(PhoneNumber::normalize('1234567890123456')); // >15 digits
        $this->assertNull(PhoneNumber::normalize('0876543210'));       // leading 0, not a cc
    }

    public function test_is_indian(): void
    {
        $this->assertTrue(PhoneNumber::isIndian('9876543210'));
        $this->assertFalse(PhoneNumber::isIndian('447911123456'));
        $this->assertFalse(PhoneNumber::isIndian('919876543210'));
    }

    public function test_for_whatsapp(): void
    {
        $this->assertSame('919876543210', PhoneNumber::forWhatsApp('9876543210'));
        $this->assertSame('447911123456', PhoneNumber::forWhatsApp('447911123456'));
    }

    public function test_for_display(): void
    {
        $this->assertSame('+91 98765 43210', PhoneNumber::forDisplay('9876543210'));
        $this->assertSame('+447911123456', PhoneNumber::forDisplay('447911123456'));
    }
}
