<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CounterEntryPage;
use App\Filament\Pages\FinancialReports;
use App\Filament\Resources\DonationResource;
use App\Filament\Resources\HallBookingResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\SevaBookingResource;
use App\Models\AdminUser;
use App\Models\Devotee;
use App\Models\Donation;
use App\Models\DonationType;
use App\Models\HallBooking;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\CounterEntryService;
use App\Services\PaymentCaptureService;
use Database\Factories\DevoteeFactory;
use Database\Factories\HallBookingFactory;
use Database\Factories\HallFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Item 6.1 — manual cash entry (walk-in devotees).
 *
 * WHAT THIS FILE HAS TO PROVE, and why each assertion is not optional:
 *
 *  1. A counter entry is INDISTINGUISHABLE from an online payment. The
 *     whole design rests on driving PaymentCaptureService::markCaptured()
 *     with a synthetic offline Payment rather than writing a sibling
 *     confirm path — so the tests assert on the SIDE EFFECTS (stock
 *     decrement, slot occupancy, receipts, notification logs), not merely
 *     on "a row exists".
 *
 *  2. The synthetic Payment is created with status 'created', NEVER
 *     'captured'. markCaptured() early-exits on an already-captured
 *     payment, so getting this wrong is a SILENT no-op — the row exists,
 *     the booking stays pending, and nothing errors. Several assertions
 *     below would pass on a row-only implementation and fail on that one,
 *     which is exactly the point.
 *
 *  3. markCaptured()'s new $paidAt parameter is purely additive. The
 *     existing suite passing is necessary but not sufficient, so
 *     test_omitting_paid_at_still_stamps_now asserts the default
 *     directly.
 *
 *  4. The strict 80G rule (item 5.4) applies to cash exactly as it does
 *     online: no valid PAN → no receipt, no receipt NUMBER burnt, and the
 *     donation is recorded as Gupt Daan.
 *
 * MySQL only. Needs the `temple_portal_test` database (phpunit.xml).
 */
class ManualCashEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PDF jobs write to the private R2 bucket; notifications may mail.
        Storage::fake('r2_private');
        Storage::fake('r2');
        Mail::fake();

        $this->seed(RolePermissionSeeder::class);
    }

    private function service(): CounterEntryService
    {
        return app(CounterEntryService::class);
    }

    /** A super admin — Gate::before grants every permission. */
    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Counter Clerk',
            'email' => 'counter-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    /**
     * A throwaway admin holding exactly the permissions given.
     *
     * @param  array<int,string>  $permissions
     */
    private function adminWith(array $permissions): AdminUser
    {
        $suffix = Str::lower(Str::random(8));

        $role = Role::create(['name' => "cash_role_{$suffix}", 'guard_name' => 'admin']);
        $role->syncPermissions(array_merge(['panel_user'], $permissions));

        $admin = AdminUser::create([
            'name' => "Limited {$suffix}",
            'email' => "limited-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    private function enableTemplate(string $key): void
    {
        NotificationTemplate::create([
            'key' => $key,
            'channel' => 'email',
            'label' => "Test — {$key}",
            'is_enabled' => true,
            'subject' => 'Confirmed',
            'body' => '<p>{{ devotee_name }}</p>',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
            'placeholder_map' => ['devotee_name' => 'devotee.name'],
        ]);
    }

    /** Drain a streamed download response into a string. */
    private function csvFrom(mixed $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $overrides */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'entry_token' => CounterEntryService::newEntryToken(),
            'payment_method' => 'cash',
            'paid_on' => now()->toDateString(),
        ], $overrides);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DONATION
    // ═══════════════════════════════════════════════════════════════

    public function test_cash_donation_is_created_confirmed_and_notified(): void
    {
        $this->enableTemplate('donation.confirmed');
        $devotee = DevoteeFactory::new()->withPan()->create(['phone' => '9876500001']);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500001',
            'donation_type' => 'annadan',
            'amount' => 2100,
        ]), $this->superAdmin());

        $payment = $result['payment'];

        // The synthetic Payment shape.
        $this->assertStringStartsWith('cash_', $payment->razorpay_order_id);
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->razorpay_payment_id, 'a cash payment has no Razorpay payment id');
        // …and it ENDED as captured, which only happens if it was created
        // as 'created'. Pre-setting 'captured' would leave every side
        // effect below unfired.
        $this->assertSame('captured', $payment->status->value);
        $this->assertNotNull($payment->paid_at);
        $this->assertEqualsWithDelta(2100.0, (float) $payment->amount, 0.001);

        $donation = Donation::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame($devotee->id, $donation->devotee_id);
        $this->assertSame('annadan', $donation->donation_type->value);
        $this->assertSame(CounterEntryService::financialYearFor(now()), $donation->financial_year);

        // Side effect: the capture-time notification really went out.
        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'donation.confirmed',
        ]);

        // Side effect: the donor has a valid PAN, so an 80G receipt with a
        // real number was minted by Generate80GReceipt during capture.
        $receipt = Receipt80G::where('donation_id', $donation->id)->first();
        $this->assertNotNull($receipt, 'a PAN-holding cash donor must get an 80G receipt');
        $this->assertSame('ABCDE1234F', $receipt->pan_number);
        $this->assertStringEndsWith('00001', $receipt->receipt_number);
        // The receipt records how the money came in.
        $this->assertSame('cash', $receipt->payment_mode);
    }

    public function test_pan_less_cash_donation_gets_no_80g_receipt_and_burns_no_number(): void
    {
        // The strict rule (item 5.4) is not an online-only rule. A cash
        // donation must go THROUGH ReceiptService::ineligibilityReason(),
        // not around it.
        DevoteeFactory::new()->create(['phone' => '9876500002']);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500002',
            'amount' => 51000, // large: the rule is amount-independent
            'wants_80g' => true,
        ]), $this->superAdmin());

        $donation = Donation::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertSame(0, Receipt80G::count(), 'no receipt row may exist');
        // No counter row at all → not a single statutory number was taken.
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $donation->financial_year,
        ]);
        $this->assertFalse((bool) $donation->receipt_generated);
        $this->assertFalse((bool) $donation->is_80g_eligible, 'the verdict column must be honest');
        // Corrected 2026-08-10: no PAN withholds the RECEIPT, nothing else.
        // The walk-in devotee did not ask for anonymity, so they stay a
        // named donor on the public lists.
        $this->assertFalse(
            (bool) $donation->anonymous,
            'a missing PAN must not turn a counter donor into Gupt Daan',
        );

        // Details are always retained — the trust must still see who paid
        // at the counter, Gupt Daan or not.
        $this->assertNotNull($donation->devotee_id);
        $this->assertSame('9876500002', $donation->fresh()->devotee->phone);

        // The money itself still captured normally.
        $this->assertSame('captured', $result['payment']->status->value);
    }

    public function test_a_gupt_daan_cash_donor_with_a_pan_still_gets_an_80g_receipt(): void
    {
        // Anonymity is a public-display choice, not a tax one — the two
        // are independent (corrected 2026-08-10).
        DevoteeFactory::new()->withPan()->create(['phone' => '9876500003']);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500003',
            'amount' => 2100,
            'wants_80g' => true,
            'anonymous' => true,
        ]), $this->superAdmin());

        $donation = Donation::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertTrue((bool) $donation->anonymous, 'the clerk recorded the devotee’s choice');
        $this->assertTrue((bool) $donation->is_80g_eligible);
        $this->assertNotNull(
            Receipt80G::where('donation_id', $donation->id)->first(),
            'Gupt Daan must not withhold a statutory receipt',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  SEVA BOOKING
    // ═══════════════════════════════════════════════════════════════

    public function test_cash_seva_booking_confirms_and_fires_its_receipt(): void
    {
        $this->enableTemplate('seva.booking.confirmed');
        DevoteeFactory::new()->create(['phone' => '9876500003']);
        $seva = SevaFactory::new()->create(['price' => 251.00]);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_SEVA,
            'phone' => '9876500003',
            'seva_id' => $seva->id,
            'booking_date' => now()->addDays(4)->toDateString(),
            'slot_time' => '10:00',
            'quantity' => 2,
        ]), $this->superAdmin());

        $booking = SevaBooking::where('payment_id', $result['payment']->id)->firstOrFail();

        // pending → confirmed happened INSIDE markCaptured. That flip is
        // also what fires SevaBookingObserver::updated(), i.e. the seva
        // reminder schedule — a booking created already-confirmed would
        // take the observer's other branch and skip the capture path.
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertEqualsWithDelta(502.00, (float) $booking->total_amount, 0.001, '251 × 2, priced server-side');

        // Side effect: the receipt PDF was rendered and its single merged
        // confirmation message dispatched (the 2026-08-04 merged flow).
        $this->assertNotNull($booking->receipt_number, 'GenerateSevaReceipt must have run');
        $this->assertNotNull($booking->receipt_path);
        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'seva.booking.confirmed',
        ]);
    }

    public function test_cash_seva_booking_occupies_its_slot(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500004']);
        DevoteeFactory::new()->create(['phone' => '9876500005']);

        // Capacity of exactly one booking on this slot.
        $seva = SevaFactory::new()->create([
            'price' => 101.00,
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time',
                'slot_duration_minutes' => 60,
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'weekly_schedule' => ['default' => [['start' => '10:00', 'end' => '11:00']]],
                'blackout_dates' => [],
            ],
        ]);

        $date = now()->addDays(5)->toDateString();
        $admin = $this->superAdmin();

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_SEVA,
            'phone' => '9876500004',
            'seva_id' => $seva->id,
            'booking_date' => $date,
            'slot_time' => '10:00',
        ]), $admin);

        // The slot is now occupied — SevaSlotService counts bookings by
        // BOOKING status, not payment status, so the row itself blocks it.
        $this->expectException(ValidationException::class);

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_SEVA,
            'phone' => '9876500005',
            'seva_id' => $seva->id,
            'booking_date' => $date,
            'slot_time' => '10:00',
        ]), $admin);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HALL BOOKING — multi-day (item 4.2)
    // ═══════════════════════════════════════════════════════════════

    public function test_cash_hall_booking_over_three_days_is_priced_flat_rate_times_days(): void
    {
        $this->enableTemplate('hall.booking.confirmed');
        DevoteeFactory::new()->create(['phone' => '9876500006']);
        $hall = HallFactory::new()->multiDay(7)->create(['price_per_day' => 5000.00]);

        $start = now()->addDays(20)->toDateString();
        $end = now()->addDays(22)->toDateString();

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_HALL,
            'phone' => '9876500006',
            'hall_id' => $hall->id,
            'hall_booking_date' => $start,
            'hall_end_date' => $end,
            'hall_purpose' => 'Wedding reception',
            'expected_guests' => 250,
        ]), $this->superAdmin());

        $booking = HallBooking::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertSame(3, (int) $booking->days_count);
        $this->assertSame($start, $booking->booking_date->toDateString());
        $this->assertSame($end, $booking->end_date->toDateString());
        // Flat rate × days — 5000 × 3, computed by HallAvailabilityService.
        $this->assertEqualsWithDelta(15000.00, (float) $booking->total_amount, 0.001);
        $this->assertEqualsWithDelta(15000.00, (float) $result['payment']->amount, 0.001);
        $this->assertSame('confirmed', $booking->status);
        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'hall.booking.confirmed',
        ]);
    }

    public function test_cash_hall_booking_blocks_every_date_in_its_range(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500007']);
        $hall = HallFactory::new()->multiDay(7)->create();

        // An existing booking sits on the MIDDLE day of the range we want.
        HallBookingFactory::new()->forHall($hall)->create([
            'booking_date' => now()->addDays(31)->toDateString(),
            'end_date' => now()->addDays(31)->toDateString(),
            'status' => 'confirmed',
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_HALL,
            'phone' => '9876500007',
            'hall_id' => $hall->id,
            'hall_booking_date' => now()->addDays(30)->toDateString(),
            'hall_end_date' => now()->addDays(32)->toDateString(),
            'hall_purpose' => 'Satsang',
        ]), $this->superAdmin());
    }

    // ═══════════════════════════════════════════════════════════════
    //  STORE ORDER
    // ═══════════════════════════════════════════════════════════════

    public function test_cash_store_order_decrements_stock_and_fills_counter_shipping(): void
    {
        $this->enableTemplate('store.order.confirmed');
        DevoteeFactory::new()->create(['phone' => '9876500008', 'name' => 'Walk-in Devotee']);
        $product = ProductFactory::new()->create(['price' => 150.00, 'stock_quantity' => 10]);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_STORE,
            'phone' => '9876500008',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]), $this->superAdmin());

        $order = Order::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertSame('confirmed', $order->status->value);
        $this->assertEqualsWithDelta(450.00, (float) $order->total_amount, 0.001);
        $this->assertSame(1, $order->items()->count());

        // THE side effect: stock decremented exactly once, by
        // markCaptured's locked, id-ordered block — not by this service.
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        // The six NOT NULL shipping_* columns are filled with counter-sale
        // defaults rather than migrated to nullable.
        $this->assertSame('Walk-in Devotee', $order->shipping_name);
        $this->assertSame('9876500008', $order->shipping_phone);
        $this->assertNotEmpty($order->shipping_address);
        $this->assertNotEmpty($order->shipping_city);
        $this->assertNotEmpty($order->shipping_state);
        $this->assertNotEmpty($order->shipping_pincode);
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_charge, 0.001);

        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'store.order.confirmed',
        ]);
    }

    public function test_cash_store_order_decrements_variant_stock(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500009']);
        $product = ProductFactory::new()->create([
            'price' => 100.00,
            'has_variants' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 120.00, 'stock' => 8],
                ['label' => 'Large', 'price' => 200.00, 'stock' => 4],
            ],
        ]);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_STORE,
            'phone' => '9876500009',
            'items' => [
                ['product_id' => $product->id, 'variant_label' => 'Large', 'quantity' => 2],
            ],
        ]), $this->superAdmin());

        // Variant price wins over the product price: 200 × 2.
        $this->assertEqualsWithDelta(400.00, (float) $result['payment']->amount, 0.001);

        $fresh = $product->fresh();
        $this->assertSame(8, $fresh->getVariantStock('Small'), 'the untouched variant must not move');
        $this->assertSame(2, $fresh->getVariantStock('Large'), '4 - 2, decremented variant-aware');
    }

    // ═══════════════════════════════════════════════════════════════
    //  BACK-DATING — the $paidAt parameter
    // ═══════════════════════════════════════════════════════════════

    public function test_a_normal_entry_lands_on_now(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500010']);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500010',
            'amount' => 501,
        ]), $this->superAdmin());

        $this->assertTrue(
            $result['payment']->paid_at->isSameDay(now()),
            'an entry with no explicit date is stamped today',
        );
        $this->assertLessThan(60, abs($result['payment']->paid_at->diffInSeconds(now())));
    }

    public function test_a_back_dated_entry_lands_on_the_given_date(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500011']);
        $threeDaysAgo = now()->subDays(3)->startOfDay();

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500011',
            'amount' => 1100,
            'paid_on' => $threeDaysAgo->toDateString(),
        ]), $this->superAdmin());

        $payment = $result['payment'];

        $this->assertSame($threeDaysAgo->toDateString(), $payment->paid_at->toDateString());
        // created_at too, not just paid_at — the 80G receipt prints the
        // donation's created_at and every period report groups on it.
        $this->assertSame($threeDaysAgo->toDateString(), $payment->created_at->toDateString());

        $donation = Donation::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame($threeDaysAgo->toDateString(), $donation->created_at->toDateString());
        // The financial year follows the date the money was RECEIVED.
        $this->assertSame(
            CounterEntryService::financialYearFor($threeDaysAgo),
            $donation->financial_year,
        );
    }

    public function test_a_future_dated_entry_is_refused(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500012']);

        $this->expectException(ValidationException::class);

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500012',
            'amount' => 100,
            'paid_on' => now()->addDays(2)->toDateString(),
        ]), $this->superAdmin());
    }

    /**
     * THE regression guard for the change to the single source of truth
     * for every payment path in the system.
     *
     * The existing suite passing is necessary but not sufficient: it would
     * also pass if $paidAt defaulted to something subtly different, since
     * PaymentCaptureServiceTest only asserts `paid_at` is not null. This
     * asserts the default is now() for a caller that omits the argument —
     * i.e. the webhook, /api/v1/payments/verify and all four web success
     * callbacks are behaviourally untouched.
     */
    public function test_omitting_paid_at_still_stamps_now(): void
    {
        $before = now()->subSecond();

        $payment = PaymentFactory::new()->create();
        SevaBookingFactory::new()->create(['payment_id' => $payment->id, 'status' => 'pending']);

        // Exactly the call shape SevaWebController::bookingSuccess makes.
        app(PaymentCaptureService::class)->markCaptured($payment, 'pay_legacy_shape');

        $after = now()->addSecond();
        $paidAt = $payment->fresh()->paid_at;

        $this->assertNotNull($paidAt);
        $this->assertTrue(
            $paidAt->betweenIncluded($before, $after),
            "omitting \$paidAt must stamp now(); got {$paidAt}",
        );

        // And the four-positional-argument webhook shape still works, with
        // $paidAt sitting harmlessly after it.
        $second = PaymentFactory::new()->create();
        app(PaymentCaptureService::class)->markCaptured($second, 'pay_hook', 'upi', ['event' => 'payment.captured']);

        $fresh = $second->fresh();
        $this->assertSame('captured', $fresh->status->value);
        $this->assertSame('upi', $fresh->method);
        $this->assertNotNull($fresh->paid_at);
        $this->assertTrue($fresh->paid_at->isSameDay(now()));
    }

    // ═══════════════════════════════════════════════════════════════
    //  IDEMPOTENCY
    // ═══════════════════════════════════════════════════════════════

    public function test_the_same_entry_submitted_twice_does_not_double_create(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500013']);
        $product = ProductFactory::new()->create(['price' => 100.00, 'stock_quantity' => 10]);
        $admin = $this->superAdmin();

        // One entry token = one counter interaction, however many times the
        // browser posts it (double click, refresh-resubmit, two clerks).
        $payload = $this->entry([
            'record_type' => CounterEntryService::TYPE_STORE,
            'phone' => '9876500013',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $first = $this->service()->record($payload, $admin);
        $second = $this->service()->record($payload, $admin);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate'], 'the replay must be recognised, not re-recorded');

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('temple_order_items')->count());
        // The decisive one: stock moved once, not twice.
        $this->assertSame(8, (int) $product->fresh()->stock_quantity);
        $this->assertSame($first['payment']->id, $second['payment']->id);
    }

    public function test_a_fresh_token_records_a_second_genuine_entry(): void
    {
        // The flip side of the guard above: idempotency must not swallow a
        // real second sale to the same devotee.
        DevoteeFactory::new()->create(['phone' => '9876500014']);
        $admin = $this->superAdmin();

        foreach ([501, 251] as $amount) {
            $this->service()->record($this->entry([
                'record_type' => CounterEntryService::TYPE_DONATION,
                'phone' => '9876500014',
                'amount' => $amount,
            ]), $admin);
        }

        $this->assertSame(2, Donation::count());
        $this->assertSame(2, Payment::count());
    }

    // ═══════════════════════════════════════════════════════════════
    //  DEVOTEE FIND-OR-CREATE
    // ═══════════════════════════════════════════════════════════════

    public function test_a_new_devotee_is_created_with_a_canonical_phone(): void
    {
        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            // Typed the way a clerk would, with a country code and spaces.
            'phone' => '+91 98765 00015',
            'devotee_name' => 'Naya Bhakt',
            'devotee_city' => 'Gandhidham',
            'devotee_language' => 'hi',
            'amount' => 101,
        ]), $this->superAdmin());

        $devotee = $result['devotee'];

        // Canonical bare 10 digits — the same key the OTP login uses. A
        // '+91 98765 00015' row would never match a login and would break
        // PhoneNumber::forWhatsApp().
        $this->assertSame('9876500015', $devotee->phone);
        $this->assertSame('Naya Bhakt', $devotee->name);
        $this->assertSame('hi', $devotee->language->value);
        // Admin-entered, NOT OTP-verified. Keep it honest.
        $this->assertNull($devotee->phone_verified_at);
    }

    public function test_an_existing_devotee_is_matched_not_duplicated(): void
    {
        $existing = DevoteeFactory::new()->create(['phone' => '9876500016', 'name' => 'Purano Bhakt']);

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '0091 98765 00016',
            'devotee_name' => 'Someone Else Entirely',
            'amount' => 101,
        ]), $this->superAdmin());

        $this->assertSame($existing->id, $result['devotee']->id);
        $this->assertSame('Purano Bhakt', $result['devotee']->fresh()->name, 'a match must not rename the devotee');
        $this->assertSame(1, Devotee::where('phone', '9876500016')->count());
    }

    public function test_an_unknown_number_without_a_name_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500017',
            'amount' => 101,
        ]), $this->superAdmin());
    }

    // ═══════════════════════════════════════════════════════════════
    //  AUDIT TRAIL
    // ═══════════════════════════════════════════════════════════════

    public function test_the_entry_records_which_admin_took_the_cash(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500018']);
        $admin = $this->superAdmin();

        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500018',
            'amount' => 751,
            'payment_method' => 'cheque',
            'reference_note' => 'Cheque 004512',
        ]), $admin);

        $payment = $result['payment'];

        $this->assertSame($admin->getKey(), $payment->created_by_admin_id);
        $this->assertSame($admin->email, $payment->createdByAdmin->email);
        $this->assertSame('cheque', $payment->method);
        // The human trace survives even if the FK is later NULLed.
        $this->assertStringContainsString('Counter entry', (string) $payment->description);
        $this->assertStringContainsString('Cheque 004512', (string) $payment->description);
        $this->assertStringContainsString($admin->email, (string) $payment->description);
        $this->assertTrue($payment->isOffline());

        // …and it is reportable: the FinancialReports CSV names the person
        // who took the cash, which is the whole point of the column.
        $this->actingAs($admin, 'admin');
        $csv = $this->csvFrom(app(FinancialReports::class)->exportCsv());
        $this->assertStringContainsString('Collected by', $csv);
        $this->assertStringContainsString($admin->name, $csv);
        $this->assertStringContainsString('cheque', $csv);

        // Forensic half: the activity log captured the capture.
        $this->assertGreaterThan(
            0,
            DB::table('activity_log')
                ->where('log_name', 'money')
                ->where('subject_type', Payment::class)
                ->where('subject_id', $payment->id)
                ->count(),
            'LogsActivity must record the money path',
        );
    }

    /**
     * `activity_log.subject_id` is POLYMORPHIC and shared. Spatie created
     * it as an unsigned bigint, which was fine while AdminUser was the only
     * model logging; four of the five money models are UUID-keyed, and a
     * UUID written into a bigint does not fail cleanly — under a permissive
     * sql_mode it TRUNCATES and the audit trail silently points at the
     * wrong row.
     *
     * The widening migration must therefore serve BOTH key shapes. This
     * asserts a full round trip for each: an integer-keyed subject
     * (AdminUser / HallBooking) and a UUID-keyed one (Payment), each
     * resolvable back to the model it describes.
     */
    public function test_the_activity_log_round_trips_both_int_and_uuid_subjects(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500020']);
        $hall = HallFactory::new()->create();
        $admin = $this->superAdmin();

        // ── UUID subject (char(36) primary key) ──────────────────────
        $result = $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_HALL,
            'phone' => '9876500020',
            'hall_id' => $hall->id,
            'hall_booking_date' => now()->addDays(15)->toDateString(),
            'hall_purpose' => 'Katha',
        ]), $admin);

        $payment = $result['payment'];
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $payment->id);

        $uuidRow = DB::table('activity_log')
            ->where('subject_type', Payment::class)
            ->where('subject_id', $payment->id)
            ->first();
        $this->assertNotNull($uuidRow, 'a UUID subject must be logged');
        $this->assertSame($payment->id, $uuidRow->subject_id, 'the UUID must survive the write byte for byte');

        // ── Integer subject (bigint primary key) ─────────────────────
        $booking = HallBooking::where('payment_id', $payment->id)->firstOrFail();
        $this->assertIsInt($booking->getKey());

        $intRow = DB::table('activity_log')
            ->where('subject_type', HallBooking::class)
            ->where('subject_id', $booking->getKey())
            ->first();
        $this->assertNotNull($intRow, 'a bigint subject must still be logged after the widening');
        $this->assertSame((string) $booking->getKey(), (string) $intRow->subject_id);

        // ── The pre-existing consumer (auto-increment AdminUser) ─────
        $admin->update(['name' => 'Renamed Clerk']);
        $this->assertGreaterThan(
            0,
            DB::table('activity_log')
                ->where('log_name', 'admin_user')
                ->where('subject_type', AdminUser::class)
                ->where('subject_id', $admin->getKey())
                ->count(),
            'AdminUser logging must be unaffected by the column widening',
        );

        // And Spatie can still hydrate the subject back off each row.
        $this->assertTrue(
            Activity::query()
                ->where('subject_type', Payment::class)
                ->where('subject_id', $payment->id)
                ->first()
                ?->subject
                ?->is($payment) ?? false,
            'the logged UUID subject must resolve back to its model',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  AUTHORIZATION
    // ═══════════════════════════════════════════════════════════════

    public function test_the_page_is_denied_without_its_permission_and_allowed_with_it(): void
    {
        $this->actingAs($this->adminWith([]), 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(CounterEntryPage::canAccess(), 'no permission → no counter page');

        $this->actingAs($this->adminWith(['page_CounterEntryPage']), 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(CounterEntryPage::canAccess());
    }

    /**
     * Renders the real Livewire component. Filament 3 resolves closure
     * parameters by type-hint or canonical name only, so a bare `fn ($q)`
     * anywhere in this page's schema throws BindingResolutionException at
     * RENDER time — something no service-level test can catch.
     */
    public function test_the_counter_page_renders_for_a_permitted_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = $this->adminWith([
            'page_CounterEntryPage', 'create_donation', 'create_order',
            'create_seva::booking', 'create_hall::booking',
        ]);
        $this->actingAs($admin, 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::test(CounterEntryPage::class)
            ->assertOk()
            // The record-type switcher offers every type this admin holds
            // the matching create_* permission for.
            ->assertFormFieldExists('record_type')
            ->assertFormFieldExists('phone')
            ->assertFormFieldExists('payment_method')
            ->assertFormFieldExists('paid_on');
    }

    /** End-to-end through the actual Livewire page, not just the service. */
    public function test_submitting_the_page_records_a_cash_donation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->enableTemplate('donation.confirmed');

        $devotee = DevoteeFactory::new()->create(['phone' => '9876500021']);
        $admin = $this->adminWith(['page_CounterEntryPage', 'create_donation']);
        $this->actingAs($admin, 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The counter offers only the trust's configured types now — the
        // hardcoded General/Seva/Annadan list is gone, so a type must exist
        // for a counter entry to be filable at all.
        $type = DonationType::firstOrCreate(
            ['slug' => 'general'],
            ['name_gu' => 'સામાન્ય', 'name_hi' => 'सामान्य', 'name_en' => 'General', 'is_active' => true],
        );

        Livewire::test(CounterEntryPage::class)
            ->fillForm([
                'record_type' => CounterEntryService::TYPE_DONATION,
                'phone' => '9876500021',
                'donation_type_id' => $type->id,
                'amount' => 1001,
                'payment_method' => 'cash',
                'paid_on' => now()->toDateString(),
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $donation = Donation::firstOrFail();
        $this->assertSame($type->id, $donation->donation_type_id);
        // The legacy enum column is kept in step from the type's slug, so
        // reports and receipts written against it still work.
        $this->assertSame('general', $donation->getRawOriginal('donation_type'));
        $this->assertSame($devotee->id, $donation->devotee_id);
        $this->assertEqualsWithDelta(1001.0, (float) $donation->amount, 0.001);
        $this->assertSame('captured', $donation->payment->status->value);
        $this->assertSame($admin->getKey(), $donation->payment->created_by_admin_id);
        $this->assertDatabaseHas('temple_notification_logs', ['template_key' => 'donation.confirmed']);
    }

    public function test_an_admin_cannot_record_a_type_they_lack_the_create_permission_for(): void
    {
        DevoteeFactory::new()->create(['phone' => '9876500019']);

        // May take cash — but only for store orders.
        $admin = $this->adminWith(['page_CounterEntryPage', 'create_order']);

        $this->assertSame(
            [CounterEntryService::TYPE_STORE],
            $this->service()->allowedTypesFor($admin),
        );

        $this->expectException(AuthorizationException::class);

        $this->service()->record($this->entry([
            'record_type' => CounterEntryService::TYPE_DONATION,
            'phone' => '9876500019',
            'amount' => 100,
        ]), $admin);
    }

    public function test_the_seeder_grants_the_counter_page_to_the_right_roles(): void
    {
        $this->assertTrue(Role::findByName('trustee', 'admin')->hasPermissionTo('page_CounterEntryPage'));
        $this->assertTrue(Role::findByName('staff', 'admin')->hasPermissionTo('page_CounterEntryPage'));

        // Accountant is read-only by design; volunteer and pujari are not
        // front-desk roles. None of them may take money.
        foreach (['accountant', 'volunteer', 'pujari'] as $role) {
            $this->assertFalse(
                Role::findByName($role, 'admin')->hasPermissionTo('page_CounterEntryPage'),
                "{$role} must not be able to take cash",
            );
        }

        // Staff run the counter for seva / hall / store, but donations
        // (which touch 80G receipt numbering) stay with trustee.
        $staff = Role::findByName('staff', 'admin');
        $this->assertTrue($staff->hasPermissionTo('create_order'));
        $this->assertTrue($staff->hasPermissionTo('create_seva::booking'));
        $this->assertTrue($staff->hasPermissionTo('create_hall::booking'));
        $this->assertFalse($staff->hasPermissionTo('create_donation'));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $permissionsBefore = DB::table('permissions')->count();
        $grantsBefore = DB::table('role_has_permissions')->count();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($permissionsBefore, DB::table('permissions')->count());
        $this->assertSame($grantsBefore, DB::table('role_has_permissions')->count());
        $this->assertSame(
            1,
            DB::table('permissions')->where('name', 'page_CounterEntryPage')->where('guard_name', 'admin')->count(),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  THE FOUR RESOURCE RAILS MUST STAY UP
    // ═══════════════════════════════════════════════════════════════

    public function test_the_four_resources_still_refuse_direct_creation(): void
    {
        // The counter page is the ONE create path. Reviving these would
        // re-open exactly the hole they were closed for: a booking with no
        // payment, hand-inserted through a generic Filament form.
        $this->assertFalse(DonationResource::canCreate());
        $this->assertFalse(SevaBookingResource::canCreate());
        $this->assertFalse(HallBookingResource::canCreate());
        $this->assertFalse(OrderResource::canCreate());
    }
}
