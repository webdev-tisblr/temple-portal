<?php

namespace Tests\Feature;

use App\Models\DailyDarshanPhoto;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Services\GreetingCardService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What fills an image overlay the donor left empty (2026-08-13; darshan
 * photo added 2026-08-16).
 *
 * A donation type can define a photo-upload extra field and leave it optional.
 * Originally a donor who skipped it received a card with a HOLE where the photo
 * belonged — applyOverlay() bailed on the empty value and drew nothing. The
 * ladder now runs: the DAY'S DAILY DARSHAN PHOTO → the configured
 * `greeting_card_fallback_image` → the bundled trust logo.
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
        // Creating a darshan photo dated today fires the booking-day
        // notification hook and the derivative job; neither is under test.
        Queue::fake();
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

    /**
     * ONE devotee for every card in a test.
     *
     * The card draws `_donor_name`, and DevoteeFactory names are random — so
     * a fresh devotee per capture made every render byte-unique and no two
     * cards could ever be compared for sameness. Holding the donor constant
     * leaves the photo as the only thing that can differ, which is what
     * these tests are actually about.
     */
    private function donor(): string
    {
        return $this->donorId ??= DevoteeFactory::new()->create()->id;
    }

    private ?string $donorId = null;

    private function capture(DonationType $type, array $extraData): Donation
    {
        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $this->donor(),
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

    /**
     * A darshan photo on R2 plus the row that points at it.
     *
     * `medium_path` is what the overlay composites from — originals are
     * 8–12 MB straight off a phone.
     */
    private function darshanPhoto(string $capturedOn, array $rgb, string $key): DailyDarshanPhoto
    {
        Storage::disk('r2')->put($key, $this->png(rgb: $rgb));

        return DailyDarshanPhoto::create([
            'image_path' => $key,
            'medium_path' => $key,
            'captured_on' => $capturedOn,
            'is_active' => true,
        ]);
    }

    /**
     * The point of the 2026-08-16 change: an empty photo slot carries that
     * day's darshan of Hanumanji, not the flat trust logo.
     *
     * Asserted by identity, not just difference — compositing the darshan
     * photo into the slot must produce the very same card as a donor who had
     * uploaded that same image, which is only true if the photo (and not the
     * logo) is what got drawn.
     */
    public function test_an_empty_photo_slot_carries_that_days_darshan_photo(): void
    {
        $type = $this->typeWithPhotoOverlay();

        $withLogo = $this->capture($type, []);
        $logoBytes = Storage::disk('r2_private')->get($withLogo->greeting_card_path);

        $photo = $this->darshanPhoto(today()->toDateString(), [200, 40, 10], 'darshan/today.png');

        $withDarshan = $this->capture($type, []);
        $darshanBytes = Storage::disk('r2_private')->get($withDarshan->greeting_card_path);

        $this->assertNotSame($logoBytes, $darshanBytes, 'the logo must no longer be what fills the slot');

        $asUpload = $this->capture($type, ['photo' => $photo->medium_path]);
        $this->assertSame(
            Storage::disk('r2_private')->get($asUpload->greeting_card_path),
            $darshanBytes,
            'the fallback must composite the darshan photo itself',
        );
    }

    /** No photo on the day → the last one uploaded before it. */
    public function test_it_falls_back_to_the_most_recent_earlier_darshan_photo(): void
    {
        $type = $this->typeWithPhotoOverlay();

        $older = $this->darshanPhoto(today()->subDays(9)->toDateString(), [10, 90, 200], 'darshan/older.png');
        $recent = $this->darshanPhoto(today()->subDays(2)->toDateString(), [20, 200, 90], 'darshan/recent.png');

        $card = $this->capture($type, []);

        $this->assertSame(
            Storage::disk('r2_private')->get($this->capture($type, ['photo' => $recent->medium_path])->greeting_card_path),
            Storage::disk('r2_private')->get($card->greeting_card_path),
        );
        $this->assertNotSame(
            Storage::disk('r2_private')->get($this->capture($type, ['photo' => $older->medium_path])->greeting_card_path),
            Storage::disk('r2_private')->get($card->greeting_card_path),
        );
    }

    /**
     * The card is anchored to ITS OWN day, not to today.
     *
     * r2_private is a regenerable cache swept every few days, so a donor who
     * opens their card link a fortnight later rebuilds it. If the fallback
     * read today()'s darshan, that rebuild would quietly hand back a
     * different picture from the one they were sent.
     */
    public function test_a_card_regenerated_later_keeps_its_own_days_darshan(): void
    {
        $type = $this->typeWithPhotoOverlay();

        $thatDay = $this->darshanPhoto(today()->subDays(12)->toDateString(), [180, 20, 120], 'darshan/that-day.png');
        $this->darshanPhoto(today()->toDateString(), [20, 180, 120], 'darshan/now.png');

        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $this->donor(),
            'payment_id' => $payment->id,
            'donation_type_id' => $type->id,
            'extra_data' => [],
            'created_at' => today()->subDays(12),
        ]);

        app(GreetingCardService::class)->generate($donation->fresh());

        $this->assertSame(
            Storage::disk('r2_private')->get($this->capture($type, ['photo' => $thatDay->medium_path])->greeting_card_path),
            Storage::disk('r2_private')->get($donation->fresh()->greeting_card_path),
            'a 12-day-old donation must rebuild with the darshan of ITS day',
        );
    }

    /** Deactivated photos are not darshan the trust is showing anyone. */
    public function test_an_inactive_darshan_photo_is_ignored(): void
    {
        $type = $this->typeWithPhotoOverlay();

        $withLogo = $this->capture($type, []);

        $this->darshanPhoto(today()->toDateString(), [200, 40, 10], 'darshan/hidden.png')
            ->update(['is_active' => false]);

        $this->assertSame(
            Storage::disk('r2_private')->get($withLogo->greeting_card_path),
            Storage::disk('r2_private')->get($this->capture($type, [])->greeting_card_path),
        );
    }

    /**
     * A seva card is anchored to the SEVA DAY, not to when it was booked or
     * rendered — the card is the keepsake of the seva being performed, and
     * the day-of sweep renders it that morning.
     *
     * Two bookings, two seva days, each with its own darshan photo, and an
     * image-only card so nothing else can differ: if the fallback read
     * today() the two cards would come out identical.
     */
    public function test_a_seva_card_carries_its_own_seva_days_darshan(): void
    {
        $this->darshanPhoto(today()->subDays(6)->toDateString(), [220, 30, 30], 'darshan/seva-a.png');
        $this->darshanPhoto(today()->subDays(3)->toDateString(), [30, 30, 220], 'darshan/seva-b.png');

        $render = function (string $sevaDay): string {
            Storage::disk('r2')->put('greeting-templates/seva.png', $this->png());

            $seva = SevaFactory::new()->create([
                'greeting_card_template' => 'greeting-templates/seva.png',
                'greeting_card_config' => ['overlays' => [
                    ['field_key' => 'photo', 'type' => 'image', 'x' => 10, 'y' => 10, 'width' => 120, 'height' => 120],
                ]],
            ]);

            $booking = SevaBookingFactory::new()->create([
                'seva_id' => $seva->id,
                'devotee_id' => $this->donor(),
                'payment_id' => PaymentFactory::new()->create(['status' => 'created'])->id,
                'booking_date' => $sevaDay,
                'status' => 'confirmed',
            ]);

            app(GreetingCardService::class)->generateForSevaBooking($booking->fresh());

            return Storage::disk('r2_private')->get($booking->fresh()->greeting_card_path);
        };

        $this->assertNotSame(
            $render(today()->subDays(6)->toDateString()),
            $render(today()->subDays(3)->toDateString()),
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

        $campaign = DonationCampaign::create([
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
