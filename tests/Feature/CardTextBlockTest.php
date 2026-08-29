<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CardRichText;
use App\Support\ProfilePrefill;
use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rich TEXT BLOCK that replaced one-variable-per-overlay card layouts
 * (2026-08-29), and the profile prefill that stops asking devotees for what
 * the trust already knows.
 *
 * The conversion is where the risk sits: the block's HTML is authored by an
 * admin but its VALUES come from devotees, and the output is handed to pango
 * as markup. An unescaped ampersand in a donor's name would fail the whole
 * render; an unescaped angle bracket would let a form field inject markup.
 */
class CardTextBlockTest extends TestCase
{
    use RefreshDatabase;

    // ── HTML → pango markup ───────────────────────────────────────────

    public function test_bold_italic_and_underline_survive_the_conversion(): void
    {
        $markup = CardRichText::toPangoMarkup('<p><b>Jay</b> <em>Siya</em> <u>Ram</u></p>');

        $this->assertSame('<b>Jay</b> <i>Siya</i> <u>Ram</u>', $markup);
    }

    public function test_paragraphs_and_breaks_become_newlines(): void
    {
        $markup = CardRichText::toPangoMarkup('<p>First line</p><p>Second<br>Third</p>');

        $this->assertSame("First line\nSecond\nThird", $markup);
    }

    public function test_inline_colour_and_size_become_pango_span_attributes(): void
    {
        $markup = CardRichText::toPangoMarkup(
            '<span style="color:#C45F12; font-size:48px">Shubh</span>'
        );

        $this->assertStringContainsString('foreground="#c45f12"', $markup);
        // Pango sizes are 1024ths of a point, and the renderer runs at 72dpi
        // where a point is a pixel — so 48px is 48 * 1024.
        $this->assertStringContainsString('size="'.(48 * 1024).'"', $markup);
    }

    public function test_a_colour_pango_cannot_parse_is_dropped_rather_than_guessed(): void
    {
        // An unparseable colour makes pango-view exit non-zero, which would
        // lose the entire block — so the attribute is omitted instead.
        $markup = CardRichText::toPangoMarkup('<span style="color:hsl(20 90% 40%)">Shubh</span>');

        $this->assertStringNotContainsString('foreground', $markup);
        $this->assertStringContainsString('Shubh', $markup);
    }

    public function test_markup_a_devotee_typed_is_escaped_not_obeyed(): void
    {
        $html = '<p>Jay Siyaram, {{ _donor_name }}</p>';

        $markup = CardRichText::toPangoMarkup(
            CardRichText::substitute($html, fn () => 'Ramesh & <b>Sons</b>')
        );

        $this->assertStringContainsString('Ramesh &amp; &lt;b&gt;Sons&lt;/b&gt;', $markup);
        // The only real <b> here would have to have come from the devotee.
        $this->assertStringNotContainsString('<b>Sons', $markup);
    }

    public function test_an_unresolved_variable_leaves_a_gap_not_its_own_name(): void
    {
        $substituted = CardRichText::substitute(
            '<p>Jay Siyaram, {{ _donor_name }}</p>',
            fn () => null,
        );

        $this->assertStringNotContainsString('_donor_name', $substituted);
        $this->assertSame('Jay Siyaram,', CardRichText::toPangoMarkup($substituted));
    }

    public function test_plain_text_keeps_the_line_breaks_the_blocks_imply(): void
    {
        $this->assertSame(
            "Jay Siyaram\nRamesh",
            CardRichText::toPlainText('<p>Jay Siyaram</p><p>Ramesh</p>'),
        );
    }

    // ── Profile prefill ───────────────────────────────────────────────

    public function test_a_field_marked_with_a_profile_source_is_filled_in(): void
    {
        $devotee = DevoteeFactory::new()->create([
            'name' => 'Jayesh Patel',
            'city' => 'Patadiya',
        ]);

        $values = ProfilePrefill::values([
            ['key' => 'in_whose_name', 'type' => 'text', 'prefill_from' => 'name'],
            ['key' => 'village', 'type' => 'text', 'prefill_from' => 'city'],
            ['key' => 'gotra', 'type' => 'text'],
        ], $devotee);

        $this->assertSame(['in_whose_name' => 'Jayesh Patel', 'village' => 'Patadiya'], $values);
    }

    public function test_a_guest_and_an_empty_profile_field_fill_nothing(): void
    {
        $fields = [['key' => 'email_copy', 'type' => 'text', 'prefill_from' => 'email']];

        $this->assertSame([], ProfilePrefill::values($fields, null));

        // A devotee who has not added an email gets a blank box, not the
        // string "null" — the key is absent rather than present-and-empty.
        $noEmail = DevoteeFactory::new()->create(['email' => null]);
        $this->assertSame([], ProfilePrefill::values($fields, $noEmail));
    }

    public function test_a_photo_question_is_never_answered_with_profile_text(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        // A field switched from text to photo can keep a stale prefill_from.
        $values = ProfilePrefill::values(
            [['key' => 'photo', 'type' => 'image', 'prefill_from' => 'name']],
            $devotee,
        );

        $this->assertSame([], $values);
    }

    public function test_decorate_attaches_the_value_without_reshaping_the_field(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);

        $decorated = ProfilePrefill::decorate([
            ['key' => 'in_whose_name', 'label' => 'Name', 'type' => 'text', 'prefill_from' => 'name'],
            ['key' => 'gotra', 'label' => 'Gotra', 'type' => 'text'],
        ], $devotee);

        $this->assertSame('Jayesh', $decorated[0]['prefill']);
        $this->assertSame('Name', $decorated[0]['label'], 'the rest of the definition is untouched');
        $this->assertNull($decorated[1]['prefill']);
    }
}
