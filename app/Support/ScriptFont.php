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
    /**
     * Font for $text's script, falling back to the Latin font.
     *
     * $bold picks the bold cut of the same family (card overlays gained a
     * bold toggle, 2026-08-17). GD cannot synthesise weight — imagettftext
     * draws exactly the file it is handed — so bold has to be a real face.
     * Each bold candidate falls back to its own regular, then to Latin, so a
     * missing bold file degrades to normal weight instead of tofu.
     */
    public static function forText(string $text, bool $bold = false): ?string
    {
        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)) {
            return self::firstExisting(array_filter([
                $bold ? resource_path('fonts/NotoSansGujarati-Bold.ttf') : null,
                resource_path('fonts/NotoSansGujarati-Regular.ttf'),
            ])) ?? self::latin($bold);
        }

        if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
            return self::firstExisting(array_filter([
                // No NotoSansDevanagari-Bold is bundled; the Serif SemiBold is
                // the only heavier Devanagari cut shipped, and reads as bold
                // at card sizes. Swap in a Sans Bold here if one is added.
                $bold ? resource_path('fonts/NotoSerifDevanagari-SemiBold.ttf') : null,
                resource_path('fonts/NotoSansDevanagari-Regular.ttf'),
            ])) ?? self::latin($bold);
        }

        return self::latin($bold);
    }

    /** Latin/general text font (DejaVu chain kept from the old resolvers). */
    public static function latin(bool $bold = false): ?string
    {
        return self::firstExisting(array_filter([
            $bold ? resource_path('fonts/DejaVuSans-Bold.ttf') : null,
            $bold ? base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf') : null,
            $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : null,
            resource_path('fonts/DejaVuSans.ttf'),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]));
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
