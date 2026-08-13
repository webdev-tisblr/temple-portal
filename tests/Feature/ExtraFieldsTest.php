<?php

namespace Tests\Feature;

use App\Models\DonationCampaign;
use App\Models\Seva;
use App\Support\ExtraFieldValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dynamic extra fields on sevas and campaigns (2026-08-13).
 *
 * DonationType has had them since April; sevas and campaigns did not, so their
 * greeting cards could only show built-in variables. All three now share one
 * label-resolution rule (HasExtraFields) and one upload path
 * (ExtraFieldValues), because four copies of "store any uploaded file under
 * extra_data.{key}" is four chances to forget the image constraint.
 */
class ExtraFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
    }

    private function definition(): array
    {
        return [
            ['key' => 'person_name', 'label_gu' => 'કોનું નામ', 'label_en' => 'Whose name', 'type' => 'text', 'required' => true],
            ['key' => 'photo', 'label_gu' => 'ફોટો', 'label_en' => 'Photo', 'type' => 'image', 'required' => false],
        ];
    }

    /** Labels resolve per locale on every model, with the Gujarati fallback. */
    public function test_labels_resolve_per_locale_on_seva_and_campaign(): void
    {
        $seva = \Database\Factories\SevaFactory::new()->create(['extra_fields' => $this->definition()]);

        $campaign = DonationCampaign::create([
            'title_gu' => 'ટેસ્ટ', 'slug' => 'extra-fields-test', 'goal_amount' => 1000,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true, 'extra_fields' => $this->definition(),
        ]);

        foreach ([$seva, $campaign] as $model) {
            $this->assertSame('Whose name', $model->localizedExtraFields('en')[0]['label']);
            $this->assertSame('કોનું નામ', $model->localizedExtraFields('gu')[0]['label']);
            // label_hi is blank in the definition, so Hindi falls back to Gujarati
            // rather than rendering an empty label.
            $this->assertSame('કોનું નામ', $model->localizedExtraFields('hi')[0]['label']);
        }
    }

    /** The model knows which of its fields accept a file. */
    public function test_image_field_keys_are_identified(): void
    {
        $seva = \Database\Factories\SevaFactory::new()->create(['extra_fields' => $this->definition()]);

        $this->assertSame(['photo'], $seva->imageExtraFieldKeys());
    }

    /** An uploaded photo is stored to R2 and replaced by its key. */
    public function test_an_uploaded_photo_is_stored_and_swapped_for_its_key(): void
    {
        $file = UploadedFile::fake()->image('devotee.jpg');

        $request = Request::create('/x', 'POST', [], [], ['extra_data' => ['photo' => $file]]);

        $stored = ExtraFieldValues::store(
            $request,
            $this->definition(),
            ['person_name' => 'Ramesh', 'photo' => $file],
            'seva-extras',
        );

        $this->assertSame('Ramesh', $stored['person_name'], 'text answers pass through untouched');
        $this->assertIsString($stored['photo']);
        $this->assertStringStartsWith('seva-extras/', $stored['photo']);
        $this->assertTrue(Storage::disk('r2')->exists($stored['photo']));
    }

    /**
     * A crafted request cannot push files to R2 under a key the admin never
     * declared as an image — only declared image fields are ever stored.
     */
    public function test_an_undeclared_field_cannot_smuggle_a_file_onto_r2(): void
    {
        $file = UploadedFile::fake()->image('evil.jpg');

        $request = Request::create('/x', 'POST', [], [], ['extra_data' => ['not_declared' => $file]]);

        $stored = ExtraFieldValues::store(
            $request,
            $this->definition(),
            ['not_declared' => $file],
            'seva-extras',
        );

        // Untouched — no R2 write happened for a key outside the definition.
        $this->assertSame($file, $stored['not_declared']);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    /** A model with no fields defined is a no-op, not an error. */
    public function test_a_model_without_definitions_passes_values_through(): void
    {
        $request = Request::create('/x', 'POST');

        $this->assertSame(['a' => 'b'], ExtraFieldValues::store($request, null, ['a' => 'b'], 'seva-extras'));
        $this->assertNull(ExtraFieldValues::store($request, [], null, 'seva-extras'));
    }

    /** Non-image uploads are rejected by the shared rule. */
    public function test_the_shared_rule_constrains_uploads_to_images(): void
    {
        $rules = ExtraFieldValues::rules();

        $this->assertArrayHasKey('extra_data', $rules);
        $this->assertArrayHasKey('extra_data.*', $rules);

        $ok = validator(
            ['extra_data' => ['photo' => UploadedFile::fake()->image('good.png')]],
            $rules,
        );
        $this->assertFalse($ok->fails());

        $bad = validator(
            ['extra_data' => ['photo' => UploadedFile::fake()->create('malware.pdf', 10, 'application/pdf')]],
            $rules,
        );
        $this->assertTrue($bad->fails(), 'a non-image upload must be rejected');
    }
}
