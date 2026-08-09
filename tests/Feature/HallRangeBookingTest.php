<?php

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Services\HallAvailabilityService;
use App\Services\RazorpayService;
use Database\Factories\DevoteeFactory;
use Database\Factories\HallBookingFactory;
use Database\Factories\HallFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 4.2 — multi-day hall bookings, and the pre-existing hall
 * double-booking race (§0.7-1 of the spec).
 *
 * Money path: pricing is flat price_per_day × days and the WHOLE range is
 * blocked (no changeover day). The price must be computed server-side.
 *
 * MySQL-only project — requires a MySQL test database (see phpunit.xml).
 */
class HallRangeBookingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): HallAvailabilityService
    {
        return app(HallAvailabilityService::class);
    }

    /** Stub Razorpay so booking never touches the network. */
    private function fakeRazorpay(): void
    {
        $stub = new class extends RazorpayService
        {
            public function __construct() {}

            public function createOrder(int $amountInPaise, string $receipt, array $notes = []): object
            {
                return (object) ['id' => 'order_test_'.Str::random(10), 'amount' => $amountInPaise];
            }
        };

        $this->app->instance(RazorpayService::class, $stub);
    }

    private function bookApi(Hall $hall, Devotee $devotee, string $start, ?string $end = null)
    {
        Sanctum::actingAs($devotee);

        return $this->postJson("/api/v1/halls/{$hall->id}/book", array_filter([
            'booking_date' => $start,
            'end_date' => $end,
            'purpose' => 'Wedding',
            'contact_name' => 'Test Devotee',
            'contact_phone' => '9876543210',
        ]));
    }

    // ── pricing ──────────────────────────────────────────────────────

    public function test_three_day_range_costs_three_times_the_day_rate(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create(['price_per_day' => 5000]);

        $price = $this->service()->priceFor($hall, '2030-08-12', '2030-08-14');

        $this->assertSame(3, $price['days']);
        $this->assertSame(15000.0, $price['total']);
    }

    public function test_single_day_range_costs_exactly_one_day(): void
    {
        $hall = HallFactory::new()->create(['price_per_day' => 5000]);

        $price = $this->service()->priceFor($hall, '2030-08-12', '2030-08-12');

        $this->assertSame(1, $price['days']);
        $this->assertSame(5000.0, $price['total']);
    }

    public function test_booking_persists_the_range_and_the_server_computed_total(): void
    {
        $this->fakeRazorpay();
        $hall = HallFactory::new()->multiDay(7)->create(['price_per_day' => 5000]);
        $devotee = DevoteeFactory::new()->create();

        $response = $this->bookApi($hall, $devotee, '2030-08-12', '2030-08-14');

        $response->assertOk();
        $this->assertSame(3, $response->json('data.days'));
        $this->assertSame(15000.0, (float) $response->json('data.amount'));

        $booking = HallBooking::first();
        $this->assertSame('2030-08-12', $booking->booking_date->toDateString());
        $this->assertSame('2030-08-14', $booking->end_date->toDateString());
        $this->assertSame(3, $booking->days_count);
        $this->assertSame(15000.0, (float) $booking->total_amount);
    }

    /** The shipped app (1.4.8+32) never sends end_date. */
    public function test_legacy_single_date_post_without_end_date_still_works(): void
    {
        $this->fakeRazorpay();
        $hall = HallFactory::new()->create(['price_per_day' => 5000]);
        $devotee = DevoteeFactory::new()->create();

        $response = $this->bookApi($hall, $devotee, '2030-08-12');

        $response->assertOk();
        $booking = HallBooking::first();
        $this->assertSame('2030-08-12', $booking->booking_date->toDateString());
        $this->assertSame('2030-08-12', $booking->end_date->toDateString());
        $this->assertSame(1, $booking->days_count);
        $this->assertSame(5000.0, (float) $booking->total_amount);
    }

    // ── full blocking of the range ───────────────────────────────────

    public function test_every_day_of_the_range_including_the_end_date_is_blocked(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-14')
            ->create(['status' => 'confirmed']);

        $month = $this->service()->rangeAvailability(
            $hall,
            \Illuminate\Support\Carbon::parse('2030-08-10'),
            \Illuminate\Support\Carbon::parse('2030-08-16'),
        );
        $byDate = collect($month)->keyBy('date');

        // The end date is fully blocked — no same-day changeover.
        foreach (['2030-08-12', '2030-08-13', '2030-08-14'] as $blocked) {
            $this->assertFalse($byDate[$blocked]['available'], "{$blocked} should be blocked");
            $this->assertSame('hall_booked', $byDate[$blocked]['reason_code']);
        }
        foreach (['2030-08-10', '2030-08-11', '2030-08-15', '2030-08-16'] as $open) {
            $this->assertTrue($byDate[$open]['available'], "{$open} should be open");
        }
    }

    // ── overlap rejection at both ends ───────────────────────────────

    public function test_overlap_is_rejected_when_the_new_range_starts_inside_an_existing_one(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-14')
            ->create(['status' => 'confirmed']);

        $verdict = $this->service()->checkRange($hall, '2030-08-14', '2030-08-16');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('hall_booked', $verdict['reason_code']);
        $this->assertSame(['2030-08-14'], $verdict['conflicting_dates']);
    }

    public function test_overlap_is_rejected_when_the_new_range_ends_inside_an_existing_one(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-14')
            ->create(['status' => 'confirmed']);

        $verdict = $this->service()->checkRange($hall, '2030-08-10', '2030-08-12');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('hall_booked', $verdict['reason_code']);
        $this->assertSame(['2030-08-12'], $verdict['conflicting_dates']);
    }

    public function test_overlap_is_rejected_when_the_new_range_swallows_an_existing_one(): void
    {
        $hall = HallFactory::new()->multiDay(30)->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-13')
            ->create(['status' => 'confirmed']);

        $verdict = $this->service()->checkRange($hall, '2030-08-10', '2030-08-16');

        $this->assertFalse($verdict['ok']);
        $this->assertSame(['2030-08-12', '2030-08-13'], $verdict['conflicting_dates']);
    }

    public function test_adjacent_ranges_do_not_conflict(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-14')
            ->create(['status' => 'confirmed']);

        $this->assertTrue($this->service()->checkRange($hall, '2030-08-15', '2030-08-17')['ok']);
        $this->assertTrue($this->service()->checkRange($hall, '2030-08-09', '2030-08-11')['ok']);
    }

    /** `completed` now occupies the hall too — unified with the seva rule. */
    public function test_completed_bookings_still_block_their_dates(): void
    {
        $hall = HallFactory::new()->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-12')
            ->create(['status' => 'completed']);

        $this->assertFalse($this->service()->checkRange($hall, '2030-08-12', '2030-08-12')['ok']);
    }

    public function test_cancelled_bookings_release_their_dates(): void
    {
        $hall = HallFactory::new()->create();
        HallBookingFactory::new()->forHall($hall)->range('2030-08-12', '2030-08-12')
            ->create(['status' => 'cancelled']);

        $this->assertTrue($this->service()->checkRange($hall, '2030-08-12', '2030-08-12')['ok']);
    }

    // ── max_booking_days cap ─────────────────────────────────────────

    public function test_range_longer_than_max_booking_days_is_rejected_with_422(): void
    {
        $this->fakeRazorpay();
        $hall = HallFactory::new()->create(); // max_booking_days = 1 (default)
        $devotee = DevoteeFactory::new()->create();

        $response = $this->bookApi($hall, $devotee, '2030-08-12', '2030-08-13');

        $response->assertStatus(422);
        $this->assertSame(0, HallBooking::count());
    }

    public function test_blackout_inside_the_range_rejects_the_whole_range(): void
    {
        $hall = HallFactory::new()->multiDay(7)->create([
            'blackout_dates' => [['date' => '2030-08-13', 'reason' => 'Trust event']],
        ]);

        $verdict = $this->service()->checkRange($hall, '2030-08-12', '2030-08-14');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('blackout', $verdict['reason_code']);
        $this->assertSame('Trust event', $verdict['reason']);
    }

    // ── concurrency: two simultaneous bookings for the same range ────

    /**
     * Proves the fix for the pre-existing hall double-booking race.
     *
     * Two REAL connections, so the locking is exercised for real rather
     * than simulated: connection A takes the locked read and inserts;
     * connection B's identical locked read must BLOCK (it times out with
     * a lock-wait error, which is the proof), and once A commits, B's
     * re-check must report the range as taken.
     *
     * Fixtures are created on the race connection (committed) because
     * RefreshDatabase's wrapping transaction on the default connection
     * would otherwise be invisible to them; they are cleaned up at the end.
     */
    public function test_two_simultaneous_bookings_for_the_same_range_cannot_both_succeed(): void
    {
        $base = Config::get('database.connections.mysql');
        Config::set('database.connections.race_a', $base);
        Config::set('database.connections.race_b', $base);

        $default = DB::getDefaultConnection();
        $a = DB::connection('race_a');
        $b = DB::connection('race_b');

        $hallId = 90001;
        $devoteeId = (string) Str::uuid();
        $start = '2030-09-10';
        $end = '2030-09-12';

        $cleanup = function () use ($a, $hallId, $devoteeId): void {
            $a->table('temple_hall_bookings')->where('hall_id', $hallId)->delete();
            $a->table('temple_halls')->where('id', $hallId)->delete();
            $a->table('temple_devotees')->where('id', $devoteeId)->delete();
        };

        try {
            $cleanup();
            $a->table('temple_devotees')->insert([
                'id' => $devoteeId, 'name' => 'Race Devotee', 'phone' => '9111100001',
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $a->table('temple_halls')->insert([
                'id' => $hallId, 'name' => 'Race Hall', 'capacity' => 100,
                'price_per_day' => 5000, 'max_booking_days' => 7,
                'booking_cutoff_hours' => 0, 'day_start_time' => '09:00:00',
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Both "requests" resolve the same hall row.
            DB::setDefaultConnection('race_a');
            $hall = Hall::find($hallId);
            $svc = new HallAvailabilityService;

            // --- Devotee A: locked check passes, row inserted, NOT committed
            $a->beginTransaction();
            $this->assertTrue($svc->hasRangeCapacityForUpdate($hall, $start, $end));
            HallBooking::create([
                'devotee_id' => $devoteeId, 'hall_id' => $hallId,
                'booking_date' => $start, 'end_date' => $end, 'days_count' => 3,
                'booking_type' => 'full_day', 'purpose' => 'Race A',
                'contact_name' => 'A', 'contact_phone' => '9111100001',
                'total_amount' => 15000, 'status' => 'pending',
            ]);

            // --- Devotee B: the SAME locked read must block on A's locks.
            DB::setDefaultConnection('race_b');
            $b->statement('SET SESSION innodb_lock_wait_timeout = 2');
            $b->beginTransaction();

            $blocked = false;
            try {
                $svc->hasRangeCapacityForUpdate($hall, $start, $end);
            } catch (\Illuminate\Database\QueryException $e) {
                $blocked = true;
            }
            $b->rollBack();

            $this->assertTrue(
                $blocked,
                'The second booking was NOT serialised by the row lock — the double-booking race is still open.',
            );

            // --- A commits; B must now see the range as taken.
            DB::setDefaultConnection('race_a');
            $a->commit();

            DB::setDefaultConnection('race_b');
            $b->beginTransaction();
            $this->assertFalse(
                $svc->hasRangeCapacityForUpdate($hall, $start, $end),
                'After the first booking committed, the second must be rejected.',
            );
            $b->rollBack();

            $this->assertSame(1, (int) $a->table('temple_hall_bookings')->where('hall_id', $hallId)->count());
        } finally {
            foreach ([$a, $b] as $conn) {
                while ($conn->transactionLevel() > 0) {
                    $conn->rollBack();
                }
            }
            $cleanup();
            DB::setDefaultConnection($default);
        }
    }
}
