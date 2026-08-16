<?php

namespace Tests\Feature;

use App\Http\Requests\CreateDonationRequest;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Services\GreetingCardService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "No type chosen" is Other, not General (2026-08-16).
 *
 * Both donate surfaces default the type to the literal `general` when the
 * donor never touches the picker — the published app cannot be changed, so
 * CreateDonationRequest corrects it for both. General Donation is a real
 * admin category; untyped gifts must not land in it.
 */
class DonationTypeDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The create_temple_donation_types migration already seeds general,
     * annadan, construction and festival (but no "other"), so these are
     * upserts by slug rather than inserts.
     */
    private function type(string $slug, string $nameEn, bool $active = true): DonationType
    {
        return DonationType::updateOrCreate(['slug' => $slug], [
            'name_gu' => $nameEn,
            'name_hi' => $nameEn,
            'name_en' => $nameEn,
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    /**
     * Runs a payload through the real FormRequest — prepareForValidation and
     * the rules both — and returns what the controllers would receive.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload): array
    {
        $request = CreateDonationRequest::create('/api/v1/donations', 'POST', $payload);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request->validated();
    }

    /** The app's silent `general` default is filed as Other and linked to it. */
    public function test_unselected_type_becomes_other_and_links_to_the_admin_other_row(): void
    {
        $this->type('general', 'General Donation');
        $other = $this->type('other', 'Other');

        $validated = $this->validate(['amount' => 1100, 'donation_type' => 'general']);

        $this->assertSame('other', $validated['donation_type']);
        $this->assertSame($other->id, $validated['donation_type_id']);
    }

    /** A donor who really picked General Donation sends its id, and keeps it. */
    public function test_an_explicit_general_pick_is_left_alone(): void
    {
        $general = $this->type('general', 'General Donation');
        $this->type('other', 'Other');

        $validated = $this->validate([
            'amount' => 501,
            'donation_type' => 'general',
            'donation_type_id' => $general->id,
        ]);

        $this->assertSame('general', $validated['donation_type']);
        $this->assertSame($general->id, $validated['donation_type_id']);
    }

    /** A slug with no id (the app's offline fallback list) is matched back. */
    public function test_a_slug_without_an_id_is_resolved_to_its_admin_type(): void
    {
        $annadan = $this->type('annadan', 'Annadan');
        $this->type('other', 'Other');

        $validated = $this->validate(['amount' => 251, 'donation_type' => 'annadan']);

        $this->assertSame('annadan', $validated['donation_type']);
        $this->assertSame($annadan->id, $validated['donation_type_id']);
    }

    /** Campaign gifts carry no type id by design and must not be rewritten. */
    public function test_campaign_donations_are_untouched(): void
    {
        $this->type('other', 'Other');

        $campaign = DonationCampaign::create([
            'title_gu' => 'ટેસ્ટ ઝુંબેશ',
            'slug' => 'donation-type-default-test',
            'goal_amount' => 100000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $validated = $this->validate([
            'amount' => 5000,
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
        ]);

        $this->assertSame('campaign', $validated['donation_type']);
        $this->assertNull($validated['donation_type_id'] ?? null);
    }

    /** With no "Other" row configured the gift is still filed as other. */
    public function test_other_type_row_is_optional(): void
    {
        $this->type('general', 'General Donation');
        DonationType::where('slug', 'other')->delete();

        $validated = $this->validate(['amount' => 100, 'donation_type' => 'general']);

        $this->assertSame('other', $validated['donation_type']);
        $this->assertNull($validated['donation_type_id']);
    }

    /** A deactivated "Other" row is not linked to. */
    public function test_an_inactive_other_row_is_not_linked(): void
    {
        $this->type('other', 'Other', active: false);

        $validated = $this->validate(['amount' => 100, 'donation_type' => 'general']);

        $this->assertSame('other', $validated['donation_type']);
        $this->assertNull($validated['donation_type_id']);
    }

    /**
     * The live "Other" type has a greeting card configured, with one image
     * extra field (`Donor_Image`). An untyped donor never sees that field, so
     * the card renders with the fallback image in the photo slot rather than
     * failing — the trust's decision (2026-08-16) is that these donors DO get
     * the Other card. Asserts the card exists, not its pixels; the failure
     * guarded against is a crashed render or a missing output file.
     */
    public function test_an_untyped_donation_still_renders_the_other_card(): void
    {
        Storage::fake('r2');
        Storage::fake('r2_private');

        $canvas = imagecreatetruecolor(200, 200);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        ob_start();
        imagepng($canvas);
        Storage::disk('r2')->put('greeting-templates/other.png', (string) ob_get_clean());
        imagedestroy($canvas);

        $other = $this->type('other', 'Other');
        $other->update([
            'extra_fields' => [
                ['key' => 'Donor_Image', 'label_gu' => 'ફોટો', 'label_en' => 'Photo', 'type' => 'image', 'required' => true],
            ],
            'greeting_card_template' => 'greeting-templates/other.png',
            'greeting_card_config' => ['overlays' => [
                ['field_key' => 'Donor_Image', 'type' => 'image', 'x' => 10, 'y' => 10, 'width' => 120, 'height' => 120],
                ['field_key' => '_donor_name', 'type' => 'text', 'x' => 10, 'y' => 170, 'font_size' => 16],
            ]],
        ]);

        $validated = $this->validate(['amount' => 1100, 'donation_type' => 'general']);
        $this->assertSame($other->id, $validated['donation_type_id']);

        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => PaymentFactory::new()->create(['status' => 'created'])->id,
            'donation_type' => $validated['donation_type'],
            'donation_type_id' => $validated['donation_type_id'],
            // The donor was never asked for Donor_Image — they never picked
            // the type. This is the whole point of the case.
            'extra_data' => null,
        ]);

        app(GreetingCardService::class)->generate($donation->fresh());

        $path = $donation->fresh()->greeting_card_path;
        $this->assertNotNull($path, 'an untyped donation must still get the Other card');
        $this->assertTrue(Storage::disk('r2_private')->exists($path));
    }

    /** A request that omits the type entirely is Other, not a 422. */
    public function test_a_missing_donation_type_is_other(): void
    {
        $other = $this->type('other', 'Other');

        $validated = $this->validate(['amount' => 100]);

        $this->assertSame('other', $validated['donation_type']);
        $this->assertSame($other->id, $validated['donation_type_id']);
    }
}
