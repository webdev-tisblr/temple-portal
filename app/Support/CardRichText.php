<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The rich TEXT BLOCK on a card: one WYSIWYG-authored paragraph carrying
 * literal wording and `{{ variables }}` together, rendered as a single run.
 *
 * WHY THIS EXISTS (2026-08-29)
 *
 * Card overlays used to be one variable each, dropped onto artwork that had
 * the sentence around them baked in — "Jay Siyaram, ______" painted into the
 * picture, with a name overlay parked on top of the blank. It never lined up:
 * the name sat on the underline instead of the baseline, its weight never
 * matched the painted words next to it, and a long name ran off the end of a
 * gap sized for a short one. Every amount and date had the same problem.
 *
 * Now the artwork is left blank where the words go and the whole sentence —
 * literal text and variables in one block — is authored in the editor. One
 * run, so wrapping, alignment, weight and size are properties of the sentence
 * rather than a hand-tuned coincidence between a picture and a coordinate.
 *
 * HOW THE HTML BECOMES PIXELS
 *
 * The editor produces small, well-known HTML. Pango — already the renderer
 * for Gujarati and Devanagari, which GD cannot shape — reads its own markup
 * dialect, which is a near-subset: <b>, <i>, <u>, <s>, <span foreground=…>,
 * <span size=…>, and literal newlines. So this class walks the HTML and emits
 * that, dropping anything with no equivalent.
 *
 * SECURITY / CORRECTNESS: every text node is escaped on the way out, so an
 * ampersand in a devotee's name, or a devotee who typed "<b>" into a form
 * field, produces those characters on the card rather than markup pango would
 * either obey or choke on.
 */
final class CardRichText
{
    /** Inline HTML tags with a direct pango equivalent. */
    private const TAG_MAP = [
        'b' => 'b',
        'strong' => 'b',
        'i' => 'i',
        'em' => 'i',
        'u' => 'u',
        'ins' => 'u',
        's' => 's',
        'strike' => 's',
        'del' => 's',
    ];

    /** Tags that end a line. <p> ends two. */
    private const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'li'];

    /**
     * Substitute `{{ token }}` placeholders using the caller's resolver.
     *
     * Runs on the RAW HTML before conversion, and escapes whatever comes back,
     * so a resolved value can never introduce markup. An unresolvable token
     * renders as nothing — a card reading "Jay Siyaram, {{ _donor_name }}" in
     * front of a devotee is worse than one reading "Jay Siyaram,".
     */
    public static function substitute(string $html, callable $resolve): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_\-]+)\s*\}\}/',
            function (array $m) use ($resolve): string {
                $value = $resolve($m[1]);

                return $value === null ? '' : htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            },
            $html,
        );
    }

    /**
     * HTML (placeholders already substituted) → pango markup.
     *
     * Returns '' when the block has no visible text, which the caller treats
     * as "draw nothing" — the same rule a blank single-variable overlay
     * follows.
     */
    public static function toPangoMarkup(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new \DOMDocument;

        // Wrap in a UTF-8 document: libxml assumes ISO-8859-1 for a bare
        // fragment and mangles every Gujarati byte. LIBXML_NOERROR because
        // editor output is a fragment, not a valid document, and the parser's
        // complaints about that are noise.
        $ok = @$document->loadHTML(
            '<?xml encoding="UTF-8"?><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        if (! $ok) {
            // Unparseable — fall back to the text with tags stripped rather
            // than losing the block entirely.
            return htmlspecialchars(strip_tags($html), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        $markup = '';
        foreach (iterator_to_array($document->childNodes) as $node) {
            $markup .= self::convertNode($node);
        }

        // Collapse the runs of blank lines that trailing <p></p>s leave behind
        // and trim the ends, so a block does not render with a gap above it.
        $markup = (string) preg_replace("/\n{3,}/", "\n\n", $markup);

        return trim($markup, "\n ");
    }

    private static function convertNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            // Collapse HTML whitespace the way a browser does — the editor
            // emits newlines and indentation that are not part of the words.
            $text = (string) preg_replace('/\s+/u', ' ', $node->textContent);

            return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'br') {
            return "\n";
        }

        $inner = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $inner .= self::convertNode($child);
        }

        if (isset(self::TAG_MAP[$tag])) {
            $pango = self::TAG_MAP[$tag];

            return trim($inner) === '' ? $inner : "<{$pango}>{$inner}</{$pango}>";
        }

        if ($tag === 'span' || $tag === 'font') {
            $attributes = self::spanAttributes($node);

            if ($attributes !== '') {
                return "<span{$attributes}>{$inner}</span>";
            }

            return $inner;
        }

        if (in_array($tag, self::BLOCK_TAGS, true)) {
            // A block always ends its line. An empty one is the author's
            // deliberate blank line, so it still contributes the newline.
            return $inner."\n";
        }

        // body, and anything else with no card meaning (images, links,
        // tables): keep the words, drop the element.
        return $inner;
    }

    /**
     * Turn a span's inline style into pango span attributes.
     *
     * Only colour, size and weight/style survive — they are the ones the
     * editor's toolbar can produce and the only ones pango understands.
     */
    private static function spanAttributes(\DOMElement $node): string
    {
        $style = strtolower((string) $node->getAttribute('style'));
        $attributes = '';

        if (preg_match('/(?:^|[;\s])color\s*:\s*([^;]+)/', $style, $m)) {
            $colour = self::normaliseColour(trim($m[1]));
            if ($colour !== null) {
                $attributes .= ' foreground="'.$colour.'"';
            }
        }

        // Pango sizes are in 1024ths of a POINT, and the renderer runs at
        // 72dpi, where a point is a pixel — so the editor's px value maps
        // straight across.
        if (preg_match('/font-size\s*:\s*([0-9.]+)px/', $style, $m)) {
            $px = (float) $m[1];
            if ($px > 0) {
                $attributes .= ' size="'.(int) round($px * 1024).'"';
            }
        }

        if (str_contains($style, 'font-weight: bold') || str_contains($style, 'font-weight:bold') || str_contains($style, 'font-weight: 700')) {
            $attributes .= ' weight="bold"';
        }

        if (str_contains($style, 'font-style: italic') || str_contains($style, 'font-style:italic')) {
            $attributes .= ' style="italic"';
        }

        return $attributes;
    }

    /**
     * `#rrggbb` or `rgb(r, g, b)` → `#rrggbb`. Anything else is dropped
     * rather than guessed: pango rejects a colour it cannot parse and the
     * whole render fails with it.
     */
    private static function normaliseColour(string $value): ?string
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            return strtolower($value);
        }

        if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
            return '#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3];
        }

        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return null;
    }

    /**
     * The plain sentence, for the GD fallback path and for previews. Keeps
     * the line breaks the blocks imply and throws away everything else.
     */
    public static function toPlainText(string $html): string
    {
        $withBreaks = (string) preg_replace('#<(?:br\s*/?|/p|/div|/li)\s*>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
