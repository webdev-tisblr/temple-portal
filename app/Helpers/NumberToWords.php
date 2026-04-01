<?php

declare(strict_types=1);

namespace App\Helpers;

class NumberToWords
{
    private static array $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private static array $tens = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public static function convert(float $amount): string
    {
        $number = (int) floor($amount);
        $paise = (int) round(($amount - $number) * 100);

        if ($number === 0) {
            return 'Zero Rupees Only';
        }

        $words = preg_replace('/\s+/', ' ', trim(self::convertNumber($number)));
        $result = $words . ' Rupees';

        if ($paise > 0) {
            $result .= ' and ' . trim(self::convertNumber($paise)) . ' Paise';
        }

        return $result . ' Only';
    }

    private static function convertNumber(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        if ($number < 0) {
            return 'Minus ' . self::convertNumber(abs($number));
        }

        $result = '';

        // Crores (1,00,00,000+)
        if ($number >= 10000000) {
            $result .= self::convertNumber((int) floor($number / 10000000)) . ' Crore ';
            $number %= 10000000;
        }

        // Lakhs (1,00,000+)
        if ($number >= 100000) {
            $result .= self::convertNumber((int) floor($number / 100000)) . ' Lakh ';
            $number %= 100000;
        }

        // Thousands (1,000+)
        if ($number >= 1000) {
            $result .= self::convertNumber((int) floor($number / 1000)) . ' Thousand ';
            $number %= 1000;
        }

        // Hundreds
        if ($number >= 100) {
            $result .= self::$ones[(int) floor($number / 100)] . ' Hundred ';
            $number %= 100;
        }

        // Tens and ones
        if ($number >= 20) {
            $result .= self::$tens[(int) floor($number / 10)] . ' ';
            $number %= 10;
        }

        if ($number > 0) {
            $result .= self::$ones[$number] . ' ';
        }

        return $result;
    }
}
