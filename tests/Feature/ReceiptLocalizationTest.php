<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Services\ReceiptService;
use App\Services\SevaReceiptService;
use App\Support\DevoteeLocale;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Item 1.5 — generated PDFs render in the DONOR's language
 * (temple_devotees.language), resolved inside the service so queued jobs
 * (which have no request locale) get it right too.
 *
 * Item 5.2 — a campaign donation's receipt prints the campaign name.
 *
 * The 80G receipt is the deliberate exception: statutory document, stays
 * English regardless of the donor's language.
 */
class ReceiptLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
    }

    /**
     * Extract the still-readable text layer from an mPDF-produced file.
     * Indic glyphs come back shaped (and therefore lossy), so assertions
     * below check Latin markers and path/locale behaviour, not glyph runs.
     */
    private function storedPdf(string $path): string
    {
        $this->assertTrue(Storage::disk('r2_private')->exists($path), "PDF missing at {$path}");

        return Storage::disk('r2_private')->get($path);
    }

    public function test_devotee_locale_resolves_from_the_language_column(): void
    {
        $this->assertSame('hi', DevoteeLocale::for(DevoteeFactory::new()->create(['language' => 'hi'])));
        $this->assertSame('en', DevoteeLocale::for(DevoteeFactory::new()->create(['language' => 'en'])));
        $this->assertSame('gu', DevoteeLocale::for(DevoteeFactory::new()->create(['language' => 'gu'])));
        // Unknown / missing devotee → Gujarati, the platform default.
        $this->assertSame('gu', DevoteeLocale::for(null));
    }

    public function test_with_locale_restores_the_previous_locale_even_on_failure(): void
    {
        app()->setLocale('en');

        try {
            DevoteeLocale::withLocale('hi', function (): void {
                throw new \RuntimeException('render exploded');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('en', app()->getLocale(), 'locale must be restored after a throwing render');
    }

    public function test_hindi_devotee_gets_a_hindi_seva_receipt(): void
    {
        $devotee = DevoteeFactory::new()->create(['language' => 'hi', 'name' => 'Ramesh']);
        $seva = SevaFactory::new()->create([
            'name_gu' => 'ગુજરાતી સેવા',
            'name_hi' => 'हिन्दी सेवा',
            'name_en' => 'English Seva',
        ]);
        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'seva_id' => $seva->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'confirmed',
        ]);

        // Render from a request locale that is NOT the devotee's, to prove
        // the service resolves the language itself (the queue-worker case).
        app()->setLocale('gu');
        $path = app(SevaReceiptService::class)->generateReceipt($booking);

        $this->assertStringEndsWith('-hi.pdf', $path, 'storage path must encode the render locale');
        $this->assertSame($path, $booking->fresh()->receipt_path);
        $this->assertSame('gu', app()->getLocale(), 'the ambient locale must be restored');

        // The Hindi label file was the one actually used for this render.
        $this->assertSame('सेवा बुकिंग रसीद', trans('receipt.title_seva', [], 'hi'));
        $this->assertNotSame(trans('receipt.title_seva', [], 'gu'), trans('receipt.title_seva', [], 'hi'));

        $bytes = $this->storedPdf($path);
        $this->assertGreaterThan(1000, strlen($bytes));
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_each_language_gets_its_own_cached_object(): void
    {
        $devotee = DevoteeFactory::new()->create(['language' => 'gu']);
        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'confirmed',
        ]);

        $service = app(SevaReceiptService::class);
        $guPath = $service->generateReceipt($booking);
        $this->assertStringEndsWith('-gu.pdf', $guPath);
        $this->assertFalse($service->needsRegeneration($booking->fresh()));

        // Devotee switches to English: the stored path is now stale, and a
        // regeneration must write a DIFFERENT object rather than serving the
        // cached Gujarati render back to them.
        $devotee->update(['language' => 'en']);
        $booking = $booking->fresh();
        $booking->setRelation('devotee', $devotee->fresh());

        $this->assertTrue($service->needsRegeneration($booking));

        $enPath = $service->generateReceipt($booking);
        $this->assertStringEndsWith('-en.pdf', $enPath);
        $this->assertNotSame($guPath, $enPath);
        $this->assertTrue(Storage::disk('r2_private')->exists($enPath));
        // HasManagedImages cascade-deletes the superseded object when the
        // path column changes, so the stale Gujarati render does not linger
        // on the private bucket.
        $this->assertFalse(Storage::disk('r2_private')->exists($guPath));
    }

    public function test_80g_receipt_stays_english_for_a_hindi_devotee(): void
    {
        // withPan() is load-bearing since the strict 80G rule landed
        // (2026-08-09): a donor with no PAN gets no 80G receipt at all, so
        // without it this test would fail on eligibility long before it got
        // near the language assertions it actually exists to make.
        $devotee = DevoteeFactory::new()->withPan()->create(['language' => 'hi', 'name' => 'Ramesh']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);

        $receipt = app(ReceiptService::class)->generateReceipt($donation);

        // No locale suffix — there is only ever one rendering of an 80G receipt.
        $this->assertStringNotContainsString('-hi.pdf', (string) $receipt->pdf_path);
        $this->assertStringNotContainsString('-gu.pdf', (string) $receipt->pdf_path);

        $bytes = $this->storedPdf($receipt->pdf_path);
        $this->assertStringStartsWith('%PDF', $bytes);

        // The statutory Blade must never grow __('receipt.*') calls.
        $blade = file_get_contents(resource_path('views/receipts/receipt-80g.blade.php'));
        $this->assertStringNotContainsString("__('receipt.", $blade,
            'the 80G receipt is English-only — it must not be wired to the receipt lang files');
        $this->assertStringContainsString('Authorised Signatory', $blade);
        $this->assertStringContainsString('Donation Receipt u/s 80G', $blade);
    }

    public function test_campaign_donation_receipt_carries_the_campaign_name(): void
    {
        $campaign = DonationCampaign::create([
            'title_gu' => 'ગૌશાળા નિર્માણ',
            'title_hi' => 'गौशाला निर्माण',
            'title_en' => 'Gaushala Construction',
            'slug' => 'gaushala-construction-'.uniqid(),
            'goal_amount' => 100000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $donation = DonationFactory::new()->create([
            // withPan() — see the note in the 80G language test above.
            'devotee_id' => DevoteeFactory::new()->withPan()->create(['language' => 'gu'])->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'donation_type' => 'campaign',
            'campaign_id' => $campaign->id,
        ]);

        $receipt = app(ReceiptService::class)->generateReceipt($donation);

        $this->assertNotNull($receipt->pdf_path);
        $this->assertSame($campaign->id, Donation::find($donation->id)->campaign_id);

        // The Blade prints it only when the service resolved a title, and the
        // 80G document prefers the ENGLISH campaign title.
        $blade = file_get_contents(resource_path('views/receipts/receipt-80g.blade.php'));
        $this->assertStringContainsString('$campaign_title', $blade);
        $this->assertStringContainsString('Donation Towards', $blade);
    }
}
