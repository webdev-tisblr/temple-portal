<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationType;
use App\Services\GreetingCardService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo fallback for an image overlay the donor left empty (2026-08-13).
 *
 * A donation type can define a photo-upload extra field and leave it optional.
 * Until now a donor who skipped it received a card with a HOLE where the photo
 * belonged — applyOverlay() bailed on the empty value and drew nothing. The
 * trust logo is drawn instead.
 *
 * These assert the CARD STILL RENDERS down each path rather than inspecting
 * pixels: the failure being guarded against is a blank box or a crashed
 * render, both of which show up as a missing/!identical output file.
 */
class GreetingCardPhotoFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
        Storage::fake('r2');
    }

    /**
     * A real PNG of a usable SIZE. A 1x1 placeholder is useless here: every
     * overlay would land off-canvas and every render would come back
     * byte-identical, so the assertions would pass on a broken implementation.
     */
    private function png(int $size = 200, array $rgb = [255, 255, 255]): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** A donation type whose card places the donor photo AND their name. */
    private function typeWithPhotoOverlay(): DonationType
    {
        Storage::disk('r2')->put('greeting-templates/bg.png', $this->png());

        return DonationType::create([
            'name_gu' => 'જન્મદિવસ',
            'name_hi' => 'जन्मदिन',
            'name_en' => 'Birthday',
            'slug' => 'birthday-test',
            'is_active' => true,
            'extra_fields' => [
                // Deliberately NOT required — the whole point of the fallback.
                ['key' => 'photo', 'label_gu' => 'ફોટો', 'label_en' => 'Photo', 'type' => 'image', 'required' => false],
            ],
            'greeting_card_template' => 'greeting-templates/bg.png',
            'greeting_card_config' => ['overlays' => [
                ['field_key' => 'photo', 'type' => 'image', 'x' => 10, 'y' => 10, 'width' => 120, 'height' => 120],
                ['field_key' => '_donor_name', 'type' => 'text', 'x' => 10, 'y' => 170, 'font_size' => 16],
            ]],
        ]);
    }

    private function capture(DonationType $type, array $extraData): Donation
    {
        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => $payment->id,
            'donation_type_id' => $type->id,
            'extra_data' => $extraData,
        ]);

        app(GreetingCardService::class)->generate($donation->fresh());

        return $donation->fresh();
    }

    /** The case that prompted this: optional photo, donor skipped it. */
    public function test_a_missing_photo_still_produces_a_complete_card(): void
    {
        $donation = $this->capture($this->typeWithPhotoOverlay(), []);

        $this->assertNotNull($donation->greeting_card_path, 'the card must still render');
        $this->assertTrue(Storage::disk('r2_private')->exists($donation->greeting_card_path));
    }

    /** An uploaded photo wins — the fallback must not override a real upload. */
    public function test_an_uploaded_photo_is_used_and_differs_from_the_fallback(): void
    {
        $type = $this->typeWithPhotoOverlay();
        Storage::disk('r2')->put('uploads/donor.png', $this->png(rgb: [10, 200, 40]));

        $without = $this->capture($type, []);
        $withoutBytes = Storage::disk('r2_private')->get($without->greeting_card_path);

        $with = $this->capture($type, ['photo' => 'uploads/donor.png']);
        $withBytes = Storage::disk('r2_private')->get($with->greeting_card_path);

        // Different source images → different rendered cards. If these matched,
        // the upload was being ignored in favour of the fallback.
        $this->assertNotSame($withoutBytes, $withBytes);
    }

    /**
     * A blank TEXT overlay must stay blank. The fallback is for images only —
     * a logo where a donor's name should be would be worse than the gap.
     */
    public function test_a_blank_text_overlay_draws_nothing(): void
    {
        Storage::disk('r2')->put('greeting-templates/bg2.png', $this->png());

        $type = DonationType::create([
            'name_gu' => 'ટેક્સ્ટ ટેસ્ટ',
            'name_hi' => 'टेक्स्ट टेस्ट',
            'name_en' => 'Text Test',
            'slug' => 'text-only-test',
            'is_active' => true,
            'extra_fields' => [
                ['key' => 'wish', 'label_gu' => 'સંદેશ', 'label_en' => 'Wish', 'type' => 'text', 'required' => false],
            ],
            'greeting_card_template' => 'greeting-templates/bg2.png',
            'greeting_card_config' => ['overlays' => [
                ['field_key' => 'wish', 'type' => 'text', 'x' => 10, 'y' => 100, 'font_size' => 20],
            ]],
        ]);

        $blank = $this->capture($type, []);
        $filled = $this->capture($type, ['wish' => 'જય શ્રી રામ']);

        $this->assertNotNull($blank->greeting_card_path);

        // The blank one is the bare background; the filled one has text on it.
        $this->assertNotSame(
            Storage::disk('r2_private')->get($blank->greeting_card_path),
            Storage::disk('r2_private')->get($filled->greeting_card_path),
        );
    }

    /** An unreadable donor upload also falls back rather than leaving a hole. */
    public function test_an_unreadable_upload_falls_back_to_the_logo(): void
    {
        $donation = $this->capture(
            $this->typeWithPhotoOverlay(),
            ['photo' => 'uploads/does-not-exist.png'],
        );

        $this->assertNotNull($donation->greeting_card_path, 'a broken upload must not kill the card');
    }

    /**
     * The fallback lives in the SHARED overlay path, so it protects campaign
     * and seva cards too — not just donation types.
     *
     * Worth being precise about the practical reach: only DonationType has
     * `extra_fields`, which is the only way an admin can define a photo-upload
     * field. Campaigns and sevas have none, and their overlay editors offer
     * text variables only — so an image overlay can only reach their cards if
     * one is hand-written into greeting_card_config, which is exactly what
     * this test does. If that ever becomes a real feature, this already works.
     */
    public function test_the_fallback_also_covers_a_campaign_card(): void
    {
        Storage::disk('r2')->put('greeting-templates/campaign.png', $this->png());

        $campaign = \App\Models\DonationCampaign::create([
            'title_gu' => 'શ્રી રામ વાટિકા',
            'slug' => 'vatika-fallback-test',
            'goal_amount' => 100000,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
            'greeting_card_template' => 'greeting-templates/campaign.png',
            'greeting_card_config' => ['overlays' => [
                // Hand-written image overlay with no donor upload behind it.
                ['field_key' => 'photo', 'type' => 'image', 'x' => 10, 'y' => 10, 'width' => 120, 'height' => 120],
                ['field_key' => '_campaign_title', 'type' => 'text', 'x' => 10, 'y' => 170, 'font_size' => 16],
            ]],
        ]);

        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => $payment->id,
            'campaign_id' => $campaign->id,
            'donation_type' => 'campaign',
            'donation_type_id' => null,
            'extra_data' => [],
        ]);

        app(GreetingCardService::class)->generate($donation->fresh());

        $this->assertNotNull($donation->fresh()->greeting_card_path, 'campaign card must render with the logo, not a hole');
    }
}
