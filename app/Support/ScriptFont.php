<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pick a TTF whose glyph coverage matches the script of the text being
 * drawn with GD (imagettftext has no font fallback — a font without the
 * needed glyphs renders tofu boxes). Mirrors the routing
 * DarshanShareCardService already uses, extended with Devanagari for
 * Hindi devotee names.
 *
 * NOTE: GD/FreeType does no OpenType shaping, so complex conjuncts render
 * in codepoint order. For the short names/labels these cards draw this is
 * acceptable; anything longer should go through mPDF (GujaratiPdf).
 */
final class ScriptFont
{
    /** Font for $text's script, falling back to the Latin font. */
    public static function forText(string $text): ?string
    {
        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)) {
            return self::firstExisting([
                resource_path('fonts/NotoSansGujarati-Regular.ttf'),
            ]) ?? self::latin();
        }

        if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
            return self::firstExisting([
                resource_path('fonts/NotoSansDevanagari-Regular.ttf'),
            ]) ?? self::latin();
        }

        return self::latin();
    }

    /** Latin/general text font (DejaVu chain kept from the old resolvers). */
    public static function latin(): ?string
    {
        return self::firstExisting([
            resource_path('fonts/DejaVuSans.ttf'),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]);
    }

    /** @param list<string> $candidates */
    private static function firstExisting(array $candidates): ?string
    {
        foreach ($candidates as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }
}
