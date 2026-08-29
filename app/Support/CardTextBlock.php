<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\GoogleFontService;
use Illuminate\Support\Facades\Log;

/**
 * Draws a rich TEXT BLOCK overlay onto a card canvas.
 *
 * Shared by all three card renderers — greeting cards, status cards and
 * darshan share cards — because they all read the same overlay config, saved
 * by the same editor. A block added on a status template has to draw the same
 * way it does on a seva card, or the editor is lying about what it produced.
 *
 * @see CardRichText for what the block's HTML becomes.
 * @see GoogleFontService for how the chosen font reaches the renderer.
 */
final class CardTextBlock
{
    public const TYPE = 'rich_text';

    /** What an unstyled block is set in when the admin picks nothing. */
    private const DEFAULT_FAMILY = 'Noto Sans Gujarati';

    private const DEFAULT_SIZE = 28.0;

    /**
     * Composite one block. Returns false when nothing was drawn — an empty
     * block, or a renderer that could not run — so the caller can decide
     * whether that matters.
     *
     * @param  array<string, mixed>  $overlay
     * @param  callable(string): ?string  $resolve  variable key → its value
     */
    public static function draw(\GdImage $image, array $overlay, callable $resolve, ?string $fallbackFontPath = null): bool
    {
        $html = (string) ($overlay['html'] ?? '');

        if (trim(strip_tags($html)) === '') {
            return false;
        }

        $substituted = CardRichText::substitute($html, $resolve);

        // A block whose only content was variables, none of which resolved,
        // draws nothing — the same rule a blank single-variable overlay
        // follows. A half-empty sentence would be worse than a gap.
        if (trim(strip_tags($substituted)) === '') {
            return false;
        }

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $width = (int) ($overlay['width'] ?? 0);
        $fontSize = (float) ($overlay['font_size'] ?? self::DEFAULT_SIZE);
        $align = (string) ($overlay['align'] ?? 'center');
        $bold = (bool) ($overlay['bold'] ?? false);
        $colour = (string) ($overlay['color'] ?? '#000000');
        $family = trim((string) ($overlay['font_family'] ?? '')) ?: self::DEFAULT_FAMILY;

        $markup = CardRichText::toPangoMarkup($substituted);

        if ($markup === '') {
            return false;
        }

        // The block's own colour is the base for anything the author did not
        // colour by hand — wrap rather than pass a --foreground so inline
        // <span foreground> still wins.
        $base = self::normaliseColour($colour);
        if ($base !== null) {
            $markup = '<span foreground="'.$base.'">'.$markup.'</span>';
        }

        $rendered = self::renderWithPango($markup, $fontSize, $family, $width, $align, $bold);

        if ($rendered instanceof \GdImage) {
            imagealphablending($image, true);
            imagecopy($image, $rendered, $x, $y, 0, 0, imagesx($rendered), imagesy($rendered));
            imagedestroy($rendered);

            return true;
        }

        return self::drawWithGd($image, $substituted, $x, $y, $width, $fontSize, $colour, $align, $fallbackFontPath);
    }

    /**
     * The real path: install the chosen font if we have not already, then let
     * pango shape and lay out the block.
     */
    private static function renderWithPango(
        string $markup,
        float $fontSize,
        string $family,
        int $width,
        string $align,
        bool $bold,
    ): ?\GdImage {
        if (! ShapedText::available()) {
            return null;
        }

        $fonts = app(GoogleFontService::class);

        // Fails open: a family that will not download still gets asked for by
        // name, and fontconfig substitutes something readable.
        $fonts->ensureInstalled($family);

        return ShapedText::renderMarkup(
            $markup,
            $fontSize,
            $family,
            $width > 0 ? $width : null,
            $align,
            $bold,
            $fonts->fontConfigPath(),
        );
    }

    /**
     * Fallback for a host with no pango — local dev, mostly.
     *
     * Deliberately plain: bold/italic/underline and per-word colour are all
     * dropped, because GD has no way to change face mid-string. The words are
     * still there and still in the right box, which is what makes a missing
     * pango a degraded card rather than a broken one. Indic text will be
     * mis-shaped here; that is the same limitation the single-variable
     * overlays have always had off-pango.
     */
    private static function drawWithGd(
        \GdImage $image,
        string $html,
        int $x,
        int $y,
        int $width,
        float $fontSize,
        string $colourHex,
        string $align,
        ?string $fontPath,
    ): bool {
        $text = CardRichText::toPlainText($html);

        if ($text === '') {
            return false;
        }

        [$r, $g, $b] = self::hexToRgb($colourHex);
        $colour = imagecolorallocate($image, $r, $g, $b);

        if (! $fontPath || ! file_exists($fontPath)) {
            imagestring($image, 5, $x, $y, $text, $colour);
            Log::info('CardTextBlock: drawn with the GD bitmap font (no pango, no TTF)');

            return true;
        }

        $lineHeight = $fontSize * 1.4;
        $lineY = $y + $fontSize;

        foreach (self::wrap($text, $fontSize, $fontPath, $width) as $line) {
            $lineX = $x;

            if ($width > 0 && $align !== 'left') {
                $box = imagettfbbox($fontSize, 0, $fontPath, $line);
                $lineWidth = abs($box[2] - $box[0]);
                $lineX = $align === 'right'
                    ? $x + max(0, $width - $lineWidth)
                    : $x + (int) round(max(0, $width - $lineWidth) / 2);
            }

            imagettftext($image, $fontSize, 0, $lineX, (int) round($lineY), $colour, $fontPath, $line);
            $lineY += $lineHeight;
        }

        return true;
    }

    /**
     * Word-wrap for the GD fallback, honouring the author's own line breaks.
     *
     * @return list<string>
     */
    private static function wrap(string $text, float $fontSize, string $fontPath, int $width): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [$text] as $paragraph) {
            if ($width <= 0) {
                $lines[] = $paragraph;

                continue;
            }

            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                $box = imagettfbbox($fontSize, 0, $fontPath, $candidate);

                if (abs($box[2] - $box[0]) > $width && $current !== '') {
                    $lines[] = $current;
                    $current = $word;

                    continue;
                }

                $current = $candidate;
            }

            $lines[] = $current;
        }

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** `#rgb` / `#rrggbb` → `#rrggbb`, or null when it is neither. */
    private static function normaliseColour(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            return strtolower($value);
        }

        if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
            return strtolower('#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3]);
        }

        return null;
    }
}
