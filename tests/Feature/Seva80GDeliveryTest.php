<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\ReceiptService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every surface that hands a devotee a seva receipt must hand over the
 * RIGHT one, and none of them may mint a statutory number.
 *
 * The four surfaces (all routed through SevaReceiptDelivery):
 *   1. the permanent signed link in WhatsApp/email  — /r/seva-receipt/{booking}
 *   2. the app                                      — GET /api/v1/bookings/{id}/receipt
 *   3. the devotee web dashboard                    — /dashboard/bookings/{id}/receipt
 *   4. the admin panel                              — EditSevaBooking (not HTTP-testable)
 *
 * Plus the app's 80G receipts list, which must now show BOTH sources.
 */
class Seva80GDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,mixed> $attributes */
    private function confirmedBooking(bool $withPan, array $attributes = []): SevaBooking
    {
        $devotee = $withPan
            ? DevoteeFactory::new()->withPan()->create()
            : DevoteeFactory::new()->create();

        return SevaBookingFactory::new()->create(array_merge([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'total_amount' => 1100,
        ], $attributes));
    }

    private function issue80G(SevaBooking $booking): Receipt80G
    {
        return app(ReceiptService::class)->generateForSevaBooking($booking);
    }

    // ── Surface 1: the permanent signed link ─────────────────────────

    public function test_the_signed_link_serves_the_80g_pdf_for_an_opted_in_booking(): void
    {
        $booking = $this->confirmedBooking(true, ['wants_80g' => true]);
        $receipt = $this->issue80G($booking);

        $response = $this->get(URL::signedRoute('seva.receipt.link', ['booking' => $booking->id]));

        $response->assertRedirect();
        $this->assertStringContainsString(
            rawurlencode(str_replace('/', '-', $receipt->receipt_number)),
            rawurlencode($response->headers->get('Location')),
        );
    }

    public function test_the_signed_link_still_serves_the_plain_pdf_otherwise(): void
    {
        $booking = $this->confirmedBooking(true, ['wants_80g' => false]);

        $this->get(URL::signedRoute('seva.receipt.link', ['booking' => $booking->id]))
            ->assertRedirect();

        // The plain path ran, so the booking carries its own SEVA-… number.
        $this->assertStringStartsWith('SEVA-', (string) $booking->fresh()->receipt_number);
        $this->assertSame(0, Receipt80G::count());
    }

    public function test_downloading_never_mints_a_statutory_number(): void
    {
        // A booking that ASKED for 80G but was never issued one (no PAN).
        // Hitting every download surface must not quietly issue it now.
        $booking = $this->confirmedBooking(false, ['wants_80g' => true]);

        $this->get(URL::signedRoute('seva.receipt.link', ['booking' => $booking->id]));

        $this->assertSame(0, Receipt80G::count());
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $booking->financial_year,
        ]);
    }

    // ── Surface 2: the app ───────────────────────────────────────────

    public function test_the_app_receipt_endpoint_serves_the_80g_pdf(): void
    {
        $booking = $this->confirmedBooking(true, ['wants_80g' => true]);
        $receipt = $this->issue80G($booking);

        $this->actingAs($booking->devotee, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/receipt")
            ->assertRedirect();

        // Still exactly one, and still the same number.
        $this->assertSame(1, Receipt80G::count());
        $this->assertSame($receipt->receipt_number, Receipt80G::first()->receipt_number);
    }

    public function test_the_bookings_list_reports_the_statutory_number(): void
    {
        $booking = $this->confirmedBooking(true, ['wants_80g' => true]);
        $receipt = $this->issue80G($booking);

        $response = $this->actingAs($booking->devotee, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk();

        $row = $response->json('data.bookings.0');
        $this->assertSame($receipt->receipt_number, $row['receipt_number']);
        $this->assertTrue($row['is_80g']);
        $this->assertTrue($row['wants_80g']);
    }

    public function test_the_bookings_list_reports_a_refused_request_honestly(): void
    {
        $booking = $this->confirmedBooking(false, ['wants_80g' => true]);

        $row = $this->actingAs($booking->devotee, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->json('data.bookings.0');

        // They asked, they did not get one. Both facts are visible.
        $this->assertTrue($row['wants_80g']);
        $this->assertFalse($row['is_80g']);
    }

    // ── Surface 3: the devotee web dashboard ─────────────────────────

    public function test_the_web_dashboard_serves_the_80g_pdf(): void
    {
        $booking = $this->confirmedBooking(true, ['wants_80g' => true]);
        $this->issue80G($booking);

        $this->actingAs($booking->devotee, 'devotee')
            ->get("/dashboard/bookings/{$booking->id}/receipt")
            ->assertRedirect();

        $this->assertSame(1, Receipt80G::count());
    }

    // ── The 80G receipts list covers BOTH sources ────────────────────

    public function test_the_80g_list_shows_seva_and_donation_receipts_together(): void
    {
        $devotee = DevoteeFactory::new()->withPan()->create();

        $donation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
        ]);
        app(ReceiptService::class)->generateReceipt($donation);

        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);
        $this->issue80G($booking);

        $rows = $this->actingAs($devotee, 'sanctum')
            ->getJson('/api/v1/receipts/80g')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['donation', 'seva'],
            array_column($rows, 'source_type'),
        );
    }

    // ── The PDF is a cache; the row is the record ────────────────────

    public function test_a_swept_pdf_regenerates_on_the_next_download(): void
    {
        // r2_private is a 7-day regenerable cache. `receipts:clean-generated`
        // deletes the object and NULLs pdf_path; every download surface must
        // rebuild it rather than 404. Verified rather than assumed: the sweep
        // walks `receipts/` and matches on exact path, and a seva 80G PDF is
        // filed under that same prefix, so it is swept like any other.
        $booking = $this->confirmedBooking(true, ['wants_80g' => true]);
        $receipt = $this->issue80G($booking);
        $originalPath = $receipt->pdf_path;

        // Simulate the sweep exactly: object gone, pointer NULLed, row kept.
        Storage::disk('r2_private')->delete($originalPath);
        $receipt->update(['pdf_path' => null]);

        $this->actingAs($booking->devotee, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/receipt")
            ->assertRedirect();

        $rebuilt = $receipt->fresh();
        $this->assertNotNull($rebuilt->pdf_path, 'the PDF was not regenerated');
        Storage::disk('r2_private')->assertExists($rebuilt->pdf_path);

        // Regeneration is a CACHE refresh, never a re-issue: same row, same
        // statutory number, and no serial burnt.
        $this->assertSame($receipt->id, $rebuilt->id);
        $this->assertSame($receipt->receipt_number, $rebuilt->receipt_number);
        $this->assertSame(1, Receipt80G::count());
    }

    public function test_the_80g_list_and_download_refuse_someone_elses_receipt(): void
    {
        $mine = DevoteeFactory::new()->withPan()->create();
        $theirs = DevoteeFactory::new()->withPan()->create();

        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => $theirs->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);
        $receipt = $this->issue80G($booking);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/receipts/80g')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/v1/receipts/80g/{$receipt->id}/download")
            ->assertStatus(403);
    }
}
