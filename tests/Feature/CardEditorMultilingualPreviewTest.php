<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DonationType;
use App\Services\CardPreviewService;
use App\Support\CardRichText;
use App\Support\CardTextBlock;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The card editor's 2026-09-12 batch: text blocks authored per language, an
 * honest canvas, and a server-side Preview that renders the UNSAVED layout
 * without storing anything.
 *
 * The greeting card is the first thing a devotee sees after giving, so the
 * rules under test are the ones whose failure a devotee would notice: the
 * wrong language's sentence, a literal "{{ _donor_name }}" on the card, a
 * preview that shows a different card from the one that ships.
 */
class CardEditorMultilingualPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // BOTH disks, or a render writes to the live bucket.
        Storage::fake('r2');
        Storage::fake('r2_private');
    }

    // ── Per-language wording ──────────────────────────────────────────

    public function test_a_block_draws_the_wording_of_the_requested_language(): void
    {
        $overlay = ['html' => '<p>જય સિયારામ</p>', 'html_hi' => '<p>जय सियाराम</p>', 'html_en' => '<p>Jay Siyaram</p>'];

        $this->assertSame('<p>જય સિયારામ</p>', CardTextBlock::htmlForLocale($overlay, 'gu'));
        $this->assertSame('<p>जय सियाराम</p>', CardTextBlock::htmlForLocale($overlay, 'hi'));
        $this->assertSame('<p>Jay Siyaram</p>', CardTextBlock::htmlForLocale($overlay, 'en'));
    }

    public function test_an_untranslated_language_falls_back_to_the_gujarati_text_not_to_nothing(): void
    {
        // A Hindi devotee must never receive a card with a blank sentence
        // because the admin has not translated it yet — same rule as the
        // per-language backgrounds.
        $overlay = ['html' => '<p>જય સિયારામ</p>', 'html_hi' => '', 'html_en' => '<p><br></p>'];

        $this->assertSame('<p>જય સિયારામ</p>', CardTextBlock::htmlForLocale($overlay, 'hi'));
        $this->assertSame('<p>જય સિયારામ</p>', CardTextBlock::htmlForLocale($overlay, 'en'), 'whitespace-only HTML counts as empty');
    }

    public function test_blocks_saved_before_languages_existed_still_render(): void
    {
        $overlay = ['html' => '<p>જય સિયારામ</p>'];

        $this->assertSame('<p>જય સિયારામ</p>', CardTextBlock::htmlForLocale($overlay, 'en'));
    }

    // ── Tokens as a contenteditable actually leaves them ─────────────

    public function test_a_token_whose_spaces_became_nbsp_is_still_substituted(): void
    {
        // Browsers rewrite the spaces around a token as &nbsp; while the
        // admin edits next to it; the old pattern only knew \s and the
        // devotee's card read "{{ _donor_name }}" verbatim.
        $html = '<p>Jay Siyaram, {{&nbsp;_donor_name&nbsp;}} and {{'."\u{00A0}".'_amount }}</p>';

        $out = CardRichText::substitute($html, fn (string $key) => ['_donor_name' => 'Ramesh', '_amount' => '₹51'][$key] ?? null);

        $this->assertSame('<p>Jay Siyaram, Ramesh and ₹51</p>', $out);
    }

    public function test_css_mode_underline_and_legacy_font_colour_reach_pango(): void
    {
        // execCommand in styleWithCSS mode emits text-decoration spans, not
        // <u>; and an older browser or a paste can still produce <font color>.
        $markup = CardRichText::toPangoMarkup(
            '<span style="text-decoration-line: underline;">Shubh</span> <font color="#c45f12">Labh</font>'
        );

        $this->assertStringContainsString('underline="single"', $markup);
        $this->assertStringContainsString('foreground="#c45f12"', $markup);
    }

    // ── The editor persists what the renderer reads ──────────────────

    public function test_the_editor_persists_every_language_and_the_alignment(): void
    {
        $editor = file_get_contents(resource_path('views/filament/components/greeting-card-editor.blade.php'));

        // syncToForm() is a whitelist — a key missing here is dropped on save.
        $this->assertStringContainsString('c.html_hi = o.html_hi', $editor);
        $this->assertStringContainsString('c.html_en = o.html_en', $editor);
        $this->assertStringContainsString("c.align = o.align || 'center'", $editor);
        $this->assertStringContainsString("value: 'justify'", $editor, 'justify must be offered');
        // A literal double-brace anywhere in this file is a Blade echo and
        // 500s the editor; the token pattern has to be assembled.
        $this->assertStringNotContainsString("'{{ '", $editor);
    }

    // ── Server-side preview ───────────────────────────────────────────

    public function test_preview_renders_the_unsaved_layout_in_the_requested_language_without_storing_it(): void
    {
        $type = $this->typeWithBackground();

        $response = $this->actingAs($this->superAdmin(), 'admin')->post(route('admin.card-preview'), [
            'owner' => 'donation_type',
            'id' => (string) $type->id,
            'locale' => 'hi',
            // Deliberately DIFFERENT from what is saved: the preview must
            // draw what the admin is looking at, not the last save.
            'config' => json_encode(['overlays' => [
                ['type' => 'rich_text', 'html' => '<p>જય સિયારામ, {{ _donor_name }}</p>', 'html_hi' => '<p>जय सियाराम, {{ _donor_name }}</p>', 'x' => 10, 'y' => 10, 'width' => 380, 'font_size' => 24, 'align' => 'left'],
                ['field_key' => '_amount', 'type' => 'text', 'x' => 10, 'y' => 120, 'font_size' => 20, 'width' => 200],
            ]]),
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'no-store, private');

        $png = $response->getContent();
        $this->assertNotFalse(@imagecreatefromstring($png), 'the body is a decodable image');

        // Nothing written anywhere: the private bucket is untouched and the
        // public one still holds only the background.
        $this->assertSame([], Storage::disk('r2_private')->allFiles());
        $this->assertSame(['greeting-templates/bg.png'], Storage::disk('r2')->allFiles());
        // The saved layout is not what was previewed and must be unchanged.
        $this->assertSame([], $type->fresh()->greeting_card_config['overlays']);
    }

    public function test_preview_differs_between_languages(): void
    {
        $type = $this->typeWithBackground();
        $config = json_encode(['overlays' => [
            ['type' => 'rich_text', 'html' => '<p>જય સિયારામ, {{ _donor_name }}</p>', 'html_en' => '<p>Jay Siyaram, {{ _donor_name }}</p>', 'x' => 10, 'y' => 10, 'width' => 380, 'font_size' => 24],
        ]]);

        $admin = $this->superAdmin();
        $gu = $this->actingAs($admin, 'admin')->post(route('admin.card-preview'), ['owner' => 'donation_type', 'id' => (string) $type->id, 'locale' => 'gu', 'config' => $config])->getContent();
        $en = $this->actingAs($admin, 'admin')->post(route('admin.card-preview'), ['owner' => 'donation_type', 'id' => (string) $type->id, 'locale' => 'en', 'config' => $config])->getContent();

        $this->assertNotSame($gu, $en, 'each language must render its own wording');
    }

    public function test_preview_works_for_a_seva_too(): void
    {
        Storage::disk('r2')->put('greeting-templates/seva.png', $this->png());
        $seva = SevaFactory::new()->create([
            'greeting_card_template' => 'greeting-templates/seva.png',
            'greeting_card_config' => ['overlays' => []],
        ]);

        $this->actingAs($this->superAdmin(), 'admin')->post(route('admin.card-preview'), [
            'owner' => 'seva',
            'id' => (string) $seva->id,
            'locale' => 'gu',
            'config' => json_encode(['overlays' => [
                ['field_key' => '_seva_name', 'type' => 'text', 'x' => 10, 'y' => 10, 'font_size' => 20, 'width' => 300],
                ['field_key' => '_slot', 'type' => 'text', 'x' => 10, 'y' => 60, 'font_size' => 20, 'width' => 300],
            ]]),
        ])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_preview_explains_a_missing_background_instead_of_failing(): void
    {
        $type = DonationType::create(['name_gu' => 'x', 'name_hi' => 'x', 'name_en' => 'x', 'slug' => 'no-bg', 'is_active' => true]);

        $this->actingAs($this->superAdmin(), 'admin')->post(route('admin.card-preview'), [
            'owner' => 'donation_type',
            'id' => (string) $type->id,
            'locale' => 'gu',
            'config' => json_encode(['overlays' => [['field_key' => '_amount', 'type' => 'text', 'x' => 0, 'y' => 0]]]),
        ])->assertStatus(422)->assertSee('No background image');
    }

    public function test_preview_is_closed_to_guests_and_to_deactivated_admins(): void
    {
        $type = $this->typeWithBackground();
        $payload = ['owner' => 'donation_type', 'id' => (string) $type->id, 'locale' => 'gu', 'config' => '{"overlays":[]}'];

        $this->post(route('admin.card-preview'), $payload)->assertRedirect();

        // Outside the Filament panel its gate does not run; the controller
        // has to re-apply canAccessPanel() itself — an inactive admin with a
        // live session cookie must still be refused.
        $inactive = $this->superAdmin();
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($inactive->fresh(), 'admin')->post(route('admin.card-preview'), $payload)->assertForbidden();
    }

    public function test_preview_rejects_an_owner_type_it_does_not_know(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')->post(route('admin.card-preview'), [
            'owner' => 'App\\Models\\AdminUser',
            'id' => '1',
            'locale' => 'gu',
            'config' => '{"overlays":[]}',
        ])->assertSessionHasErrors('owner');
    }

    public function test_every_editor_owner_has_a_preview_alias(): void
    {
        foreach (CardPreviewService::OWNERS as $alias => $class) {
            $this->assertSame($alias, CardPreviewService::aliasFor(new $class), $class);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Card Admin',
            'email' => 'cards-'.uniqid().'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    private function typeWithBackground(): DonationType
    {
        Storage::disk('r2')->put('greeting-templates/bg.png', $this->png());

        return DonationType::create([
            'name_gu' => 'જન્મદિવસ',
            'name_hi' => 'जन्मदिन',
            'name_en' => 'Birthday',
            'slug' => 'birthday-preview-'.uniqid(),
            'is_active' => true,
            'greeting_card_template' => 'greeting-templates/bg.png',
            'greeting_card_config' => ['overlays' => []],
        ]);
    }

    /** A real PNG of a usable size, so overlays land on-canvas. */
    private function png(int $size = 400): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 250, 240));

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
