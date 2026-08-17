<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ScriptFont;
use Tests\TestCase;

/**
 * Card text overlays gained a bold toggle (2026-08-17), shared by every
 * surface that uses the greeting-card editor: seva greeting cards, donation
 * type cards, campaign cards, darshan card templates and status templates.
 *
 * GD cannot synthesise weight — it draws exactly the .ttf it is handed — so
 * "bold" has to resolve to a real bold face per script, and must degrade to
 * the regular face rather than to tofu when one isn't bundled.
 */
class CardOverlayBoldTest extends TestCase
{
    public function test_latin_bold_resolves_to_a_bold_face(): void
    {
        $regular = ScriptFont::forText('Bhakt Ji');
        $bold = ScriptFont::forText('Bhakt Ji', true);

        $this->assertNotNull($bold);
        $this->assertNotSame($regular, $bold, 'bold must not reuse the regular file');
        $this->assertStringContainsStringIgnoringCase('bold', basename((string) $bold));
    }

    public function test_gujarati_bold_resolves_to_the_gujarati_bold_face(): void
    {
        $bold = ScriptFont::forText('પ્રવીણભાઈ', true);

        $this->assertSame('NotoSansGujarati-Bold.ttf', basename((string) $bold));
    }

    public function test_devanagari_bold_stays_a_devanagari_face(): void
    {
        // No Sans Bold is bundled, so this falls to the Serif SemiBold — the
        // point of the assertion is that it never falls back to a Latin font,
        // which would render Hindi names as tofu boxes.
        $bold = ScriptFont::forText('श्री राम', true);

        $this->assertStringContainsString('Devanagari', basename((string) $bold));
    }

    public function test_the_default_is_still_the_regular_face(): void
    {
        // Overlays saved before the toggle carry no `bold` key and must keep
        // rendering exactly as they do today.
        $this->assertSame('NotoSansGujarati-Regular.ttf', basename((string) ScriptFont::forText('પ્રવીણભાઈ')));
        $this->assertSame('NotoSansDevanagari-Regular.ttf', basename((string) ScriptFont::forText('श्री राम')));
    }

    /**
     * The editor rebuilds each overlay from an explicit whitelist on save, so
     * a property missing from that list is silently dropped. `bold` was added
     * to it; this guards the next person who adds a property and forgets.
     */
    public function test_the_editor_persists_the_bold_flag(): void
    {
        $editor = file_get_contents(resource_path('views/filament/components/greeting-card-editor.blade.php'));

        $this->assertStringContainsString('c.bold = !!o.bold', $editor, 'bold is dropped by syncToForm');
        $this->assertStringContainsString('overlays[selectedIdx].bold = !overlays[selectedIdx].bold', $editor, 'no bold toggle control');
        // The preview used to hardcode font-weight:bold, which made every
        // overlay look bold regardless of how it actually rendered.
        $this->assertStringNotContainsString('font-weight:bold;', $editor, 'preview still hardcodes bold');
    }
}
