<?php

namespace Tests\Feature;

use App\Services\GreetingCardService;
use App\Services\SevaReceiptService;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regression cover for 2026-08-12: generated documents were frozen in
 * whatever language they were FIRST rendered in.
 *
 * Every service already knew how to detect the problem — SevaReceiptService,
 * HallInvoiceService and InvoiceService each shipped a needsRegeneration()
 * comparing the stored path's `-{locale}` suffix against the devotee's
 * current language — but the method had ZERO callers. All ~11 download
 * surfaces tested `empty($path)` instead, so a devotee who switched language
 * kept being served the old-language PDF until the 7-day sweep. Greeting
 * cards were worse: their paths carried no locale at all, so the mismatch
 * was undetectable.
 *
 * These links are permanent and live in WhatsApp history for months, which
 * is exactly the window in which a language switch happens.
 */
class DocumentLocaleSelfHealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
    }

    public function test_signed_receipt_link_rerenders_after_the_devotee_switches_language(): void
    {
        $devotee = DevoteeFactory::new()->create(['language' => 'gu']);
        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'confirmed',
        ]);

        $guPath = app(SevaReceiptService::class)->generateReceipt($booking);
        $this->assertStringEndsWith('-gu.pdf', $guPath);

        // The devotee switches to Hindi in their profile. The stored path is
        // now stale — but it is NOT null, which is all the old guard checked.
        $devotee->update(['language' => 'hi']);

        // Hit the permanent signed link exactly as a forwarded WhatsApp
        // message would. The final redirect needs a presign-capable disk
        // (the fake has none), so the assertion is on the regeneration
        // side effect, which runs before the redirect is built.
        try {
            $this->get(URL::signedRoute('seva.receipt.link', ['booking' => $booking->id]));
        } catch (\Throwable) {
            // Presign unsupported on the fake disk — irrelevant here.
        }

        $this->assertStringEndsWith(
            '-hi.pdf',
            (string) $booking->fresh()->receipt_path,
            'the signed link must re-render in the language the devotee uses NOW',
        );
    }

    public function test_greeting_card_paths_are_locale_scoped(): void
    {
        $devotee = DevoteeFactory::new()->create(['language' => 'gu']);
        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'confirmed',
        ]);

        $service = app(GreetingCardService::class);

        // A card keyed on the id alone (the pre-fix format) can never be
        // told apart from one rendered in another language.
        $this->assertSame(
            'greeting-cards/seva/'.$booking->id.'-gu.png',
            $service->pathForSevaBooking($booking, 'gu'),
        );
        $this->assertNotSame(
            $service->pathForSevaBooking($booking, 'gu'),
            $service->pathForSevaBooking($booking, 'hi'),
        );

        // A card stored under the old locale-less key reads as stale, so the
        // download endpoint regenerates rather than serving it forever.
        $booking->update(['greeting_card_path' => 'greeting-cards/seva/'.$booking->id.'.png']);
        $this->assertTrue($service->sevaCardNeedsRegeneration($booking->fresh()));

        // Matching locale → no needless re-render on every single hit.
        $booking->update(['greeting_card_path' => $service->pathForSevaBooking($booking, 'gu')]);
        $this->assertFalse($service->sevaCardNeedsRegeneration($booking->fresh()));

        // ...until the devotee switches language.
        $devotee->update(['language' => 'en']);
        $this->assertTrue($service->sevaCardNeedsRegeneration($booking->fresh()));
    }
}
