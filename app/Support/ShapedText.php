<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Complex-script text rendering for GD-generated images (status cards,
 * greeting cards).
 *
 * GD's imagettftext() places glyphs one after another with no OpenType
 * shaping, which destroys Indic scripts: Gujarati/Devanagari matras and
 * conjuncts (કા, ર્ષ, क्ष, श्री) come out in the wrong visual order —
 * user-visible on real cards, 2026-07-26. Pango (the same shaping stack
 * Flutter and every Linux browser use) renders a transparent PNG of the
 * properly shaped text, which callers composite onto their canvas.
 *
 * Requirements on the host: `pango1.0-tools` (pango-view) and the Noto
 * Indic fonts (`fonts-noto-core`) — installed on the VPS. When either
 * is missing, callers fall back to their legacy GD path, so local dev
 * without pango degrades instead of breaking.
 */
final class ShapedText
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            $bin = trim((string) shell_exec('command -v pango-view 2>/dev/null'));
            self::$available = $bin !== '';
        }

        return self::$available;
    }

    /** Devanagari (0900–097F) or Gujarati (0A80–0AFF) present? */
    public static function needsShaping(string $text): bool
    {
        return (bool) preg_match('/[\x{0900}-\x{097F}\x{0A80}-\x{0AFF}]/u', $text);
    }

    /**
     * Render one text block to a transparent-background GdImage, shaped
     * correctly. Font family follows the dominant Indic script; Latin
     * mixed into the run falls back via fontconfig automatically.
     *
     * @param  float  $fontSizePx  Desired glyph size in pixels (rendered at 72dpi where pt == px).
     * @param  string  $hexColor  '#RRGGBB' (or without '#').
     * @param  int|null  $wrapWidthPx  When set, pango wraps and centres lines within this width.
     * @return \GdImage|null null on any failure — caller uses its GD fallback.
     */
    public static function render(string $text, float $fontSizePx, string $hexColor, ?int $wrapWidthPx = null): ?\GdImage
    {
        if (! self::available() || trim($text) === '') {
            return null;
        }

        $family = preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)
            ? 'Noto Sans Gujarati'
            : 'Noto Sans Devanagari';

        $tmp = tempnam(sys_get_temp_dir(), 'shaped-');
        if ($tmp === false) {
            return null;
        }
        $png = $tmp.'.png';

        $cmd = sprintf(
            'pango-view -q --dpi=72 -o %s --background=transparent --foreground=%s --font=%s %s --align=center -t %s 2>/dev/null',
            escapeshellarg($png),
            escapeshellarg('#'.ltrim($hexColor, '#')),
            escapeshellarg($family.' '.round($fontSizePx, 1)),
            $wrapWidthPx !== null && $wrapWidthPx > 0 ? '--width='.(int) $wrapWidthPx.' --wrap=word-char' : '',
            escapeshellarg($text),
        );

        exec($cmd, $output, $code);
        @unlink($tmp);

        if ($code !== 0 || ! is_file($png)) {
            @unlink($png);

            return null;
        }

        $image = @imagecreatefrompng($png);
        @unlink($png);

        return $image instanceof \GdImage ? $image : null;
    }
}
