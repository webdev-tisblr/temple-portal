<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * THE one place a card overlay becomes pixels.
 *
 * Greeting cards (donation type / campaign / seva), status cards and darshan
 * share-card templates are all designed in the same admin editor and saved in
 * the same overlay format — yet until 2026-09-12 each service carried its own
 * copy of the text and image painters. Three copies meant three places for a
 * placement rule to drift, and no way to promise the admin that what the
 * editor showed is what a devotee gets. Now the services (and the admin's
 * live PREVIEW, which is the whole point) all call this, so a fix here is a
 * fix everywhere and the preview cannot lie.
 *
 * What a caller supplies:
 *
 *   • $resolveText(key) → the string a `text` overlay or a `{{ variable }}`
 *     inside a rich block should show, or null for "nothing" — a blank text
 *     overlay draws nothing, deliberately.
 *   • $resolveImage(key) → the raw BYTES for an `image` overlay, or null to
 *     leave the slot empty. Any fallback ladder (darshan photo, logo…) is the
 *     caller's business; this class only paints what it is given.
 *   • $locale → which language of a rich block's wording to draw.
 *
 * Coordinate contract (shared with the editor blade): x/y are the TOP-LEFT of
 * the overlay's box in the stored background's pixel space; `width` is the
 * wrap/alignment box for words and the box size for photos. Nothing here adds
 * a margin or nudges a baseline — the editor positions the same box at the
 * same corner, so the two agree to the pixel.
 */
final class CardOverlayPainter
{
    public const LOCALES = ['gu', 'hi', 'en'];

    /**
     * Paint every overlay onto $image, in saved order.
     *
     * @param  list<array<string, mixed>>  $overlays
     * @param  callable(string): ?string  $resolveText
     * @param  callable(string): ?string  $resolveImage
     */
    public static function compose(
        \GdImage $image,
        array $overlays,
        callable $resolveText,
        callable $resolveImage,
        string $locale = 'gu',
        ?string $fallbackFontPath = null,
    ): void {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'gu';
        $fallbackFontPath ??= self::defaultFontPath();

        foreach ($overlays as $overlay) {
            if (! is_array($overlay)) {
                continue;
            }

            $type = (string) ($overlay['type'] ?? 'text');

            // A rich TEXT BLOCK carries its own wording, so it has no single
            // field_key and is handled before the guard below.
            if ($type === CardTextBlock::TYPE) {
                CardTextBlock::draw($image, $overlay, $resolveText, $fallbackFontPath, $locale);

                continue;
            }

            $fieldKey = $overlay['field_key'] ?? null;
            if (! is_string($fieldKey) || $fieldKey === '') {
                continue;
            }

            if ($type === 'image') {
                $bytes = $resolveImage($fieldKey);
                if ($bytes !== null && $bytes !== '') {
                    self::paintImage($image, $overlay, $bytes);
                }

                continue;
            }

            $value = $resolveText($fieldKey);
            if ($value === null || $value === '') {
                continue;
            }

            self::paintText($image, $overlay, (string) $value, $fallbackFontPath);
        }
    }

    /**
     * A single-variable `text` overlay: one value, wrapped and centred inside
     * the overlay's width box, top edge at y.
     *
     * Pango draws it whenever the host has it — Indic or Latin alike — in
     * the family the editor previews it in (Noto Sans Gujarati / Devanagari
     * for those scripts, DejaVu Sans otherwise). Before 2026-09-12 only Indic
     * text went through pango and it kept pango-view's default 10px margin,
     * so every shaped overlay sat 10px right and below where the editor put
     * it. GD remains the fallback for hosts without pango and for rotated
     * overlays (pango-view cannot rotate).
     */
    public static function paintText(\GdImage $image, array $overlay, string $text, ?string $fallbackFontPath = null): void
    {
        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $fontSize = (float) ($overlay['font_size'] ?? 16);
        $colourHex = self::normaliseHex((string) ($overlay['color'] ?? '#000000'));
        $angle = (float) ($overlay['angle'] ?? 0);
        // Overlays saved before the bold toggle existed have no key → normal.
        $bold = (bool) ($overlay['bold'] ?? false);
        $width = (int) ($overlay['width'] ?? 0);

        if ($angle === 0.0 && ShapedText::available()) {
            $png = ShapedText::render(
                $text,
                $fontSize,
                $colourHex,
                $width > 0 ? $width : null,
                self::familyForText($text),
                $bold,
                margin: 0,
            );

            if ($png instanceof \GdImage) {
                // With a width, pango-view's PNG IS the width box and the
                // centring is already inside it; without one the PNG is the
                // text's own size and sits at x.
                imagealphablending($image, true);
                imagecopy($image, $png, $x, $y, 0, 0, imagesx($png), imagesy($png));
                imagedestroy($png);

                return;
            }
        }

        $fontPath = ScriptFont::forText($text, $bold) ?? $fallbackFontPath;
        [$r, $g, $b] = self::hexToRgb($colourHex);
        $colour = imagecolorallocate($image, $r, $g, $b);

        if ($fontPath && file_exists($fontPath) && $width > 0 && $angle === 0.0) {
            // Centre each line within the overlay's width box, wrapping long
            // text onto new lines. GD's y is the BASELINE; the editor's y is
            // the top of the line box, so the first baseline sits one em down.
            $lineHeight = $fontSize * 1.4;
            $ly = $y + $fontSize;
            foreach (self::wrap($text, $fontSize, $fontPath, $width) as $line) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
                $lineW = abs($bbox[2] - $bbox[0]);
                $lx = $x + (int) round(max(0, $width - $lineW) / 2);
                imagettftext($image, $fontSize, 0, $lx, (int) round($ly), $colour, $fontPath, $line);
                $ly += $lineHeight;
            }

            return;
        }

        if ($fontPath && file_exists($fontPath)) {
            imagettftext($image, $fontSize, $angle, $x, $y + (int) round($fontSize * 1.2), $colour, $fontPath, $text);

            return;
        }

        imagestring($image, min(5, max(1, (int) round($fontSize / 4))), $x, $y, $text, $colour);
        Log::info('CardOverlayPainter: drawn with the GD bitmap font (no pango, no TTF)');
    }

    /**
     * The font family pango is asked for on a single-variable overlay. Kept
     * in ONE place because the editor blade previews with the same names
     * (loaded from Google Fonts for the Indic pair, DejaVu's metric twin
     * Verdana for Latin), and the two must never disagree.
     */
    public static function familyForText(string $text): string
    {
        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)) {
            return 'Noto Sans Gujarati';
        }

        if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
            return 'Noto Sans Devanagari';
        }

        return 'DejaVu Sans';
    }

    /**
     * An `image` overlay: cover-fit the photo into the box, square or circle,
     * upright per its EXIF orientation.
     */
    public static function paintImage(\GdImage $image, array $overlay, string $bytes): void
    {
        $photo = @imagecreatefromstring($bytes);
        if (! $photo instanceof \GdImage) {
            return;
        }

        // Phone photos carry an EXIF orientation tag; GD ignores it, so a
        // portrait shot would composite sideways.
        $photo = self::applyExifOrientation($photo, $bytes);

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $srcW = imagesx($photo);
        $srcH = imagesy($photo);
        $w = (int) ($overlay['width'] ?? $srcW);
        $h = (int) ($overlay['height'] ?? $srcH);

        if (($overlay['shape'] ?? 'square') === 'circle') {
            self::coverIntoCircle($image, $photo, $x, $y, $w, $h, $srcW, $srcH);
        } else {
            self::coverInto($image, $photo, $x, $y, $w, $h, $srcW, $srcH);
        }

        imagedestroy($photo);
    }

    /** The general-purpose GD fallback face, wherever this host keeps it. */
    public static function defaultFontPath(): ?string
    {
        foreach ([
            resource_path('fonts/DejaVuSans.ttf'),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    // ── internals ─────────────────────────────────────────────────────

    /**
     * Greedy word-wrap for the GD path. Very long single words stay intact.
     *
     * @return list<string>
     */
    private static function wrap(string $text, float $fontSize, string $fontPath, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current.' '.$word;
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $trial);
            if (abs($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [$text];
    }

    /**
     * Composite $src into the $w×$h box at ($x,$y) using "cover" scaling: fill
     * the box while preserving aspect ratio, centre-cropping the overflow, so
     * a photo is never squeezed when the box shape differs from the photo's.
     */
    private static function coverInto(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, int $srcW, int $srcH): void
    {
        if ($w <= 0 || $h <= 0 || $srcW <= 0 || $srcH <= 0) {
            return;
        }

        [$srcX, $srcY, $cropW, $cropH] = self::coverCrop($w, $h, $srcW, $srcH);

        imagecopyresampled($dst, $src, $x, $y, $srcX, $srcY, $w, $h, max(1, $cropW), max(1, $cropH));
    }

    /**
     * Like coverInto(), clipped to the ellipse inscribed in the box, with a
     * ~1.5px anti-aliased edge so the circle is not jagged.
     */
    private static function coverIntoCircle(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, int $srcW, int $srcH): void
    {
        if ($w <= 0 || $h <= 0 || $srcW <= 0 || $srcH <= 0) {
            return;
        }

        $temp = imagecreatetruecolor($w, $h);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
        imagefilledrectangle($temp, 0, 0, $w, $h, $transparent);

        [$srcX, $srcY, $cropW, $cropH] = self::coverCrop($w, $h, $srcW, $srcH);
        imagecopyresampled($temp, $src, 0, 0, $srcX, $srcY, $w, $h, max(1, $cropW), max(1, $cropH));

        $rx = $w / 2.0;
        $ry = $h / 2.0;
        $feather = 1.5 / min($rx, $ry);
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $nx = ($px + 0.5 - $rx) / $rx;
                $ny = ($py + 0.5 - $ry) / $ry;
                $d = sqrt($nx * $nx + $ny * $ny);
                $coverage = max(0.0, min(1.0, (1.0 - $d) / $feather + 0.5));
                if ($coverage >= 1.0) {
                    continue; // fully inside — keep the opaque photo pixel
                }
                $rgba = imagecolorat($temp, $px, $py);
                $alpha = (int) round((1.0 - $coverage) * 127);
                imagesetpixel($temp, $px, $py, ($alpha << 24) | ($rgba & 0xFFFFFF));
            }
        }

        imagealphablending($dst, true);
        imagecopy($dst, $temp, $x, $y, 0, 0, $w, $h);
        imagedestroy($temp);
    }

    /** @return array{0:int,1:int,2:int,3:int} [srcX, srcY, cropW, cropH] */
    private static function coverCrop(int $w, int $h, int $srcW, int $srcH): array
    {
        $targetRatio = $w / $h;
        $srcRatio = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            // Source wider than the box → crop the sides.
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);

            return [(int) round(($srcW - $cropW) / 2), 0, $cropW, $cropH];
        }

        // Source taller than the box → crop top/bottom.
        $cropW = $srcW;
        $cropH = (int) round($srcW / $targetRatio);

        return [0, (int) round(($srcH - $cropH) / 2), $cropW, $cropH];
    }

    /**
     * Re-orient a GD image per its source EXIF Orientation tag. Safe no-op
     * for images without one (PNGs, already-upright JPEGs).
     */
    private static function applyExifOrientation(\GdImage $photo, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $photo;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        } catch (\Throwable) {
            return $photo;
        }

        $orientation = (int) ($exif['Orientation'] ?? 0);
        if ($orientation <= 1) {
            return $photo;
        }

        // Mirror first for the flipped orientations, then rotate.
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($photo, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($photo, $angle, 0);
            if ($rotated instanceof \GdImage) {
                imagedestroy($photo);

                return $rotated;
            }
        }

        return $photo;
    }

    /** `#rgb` / `rgb` / `#rrggbb` → `#rrggbb`; anything else → black. */
    private static function normaliseHex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.(strlen($hex) === 6 && ctype_xdigit($hex) ? strtolower($hex) : '000000');
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
