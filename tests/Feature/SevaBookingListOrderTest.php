<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings;
use App\Models\AdminUser;
use App\Models\SevaBooking;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seva bookings list opens on what the trust has to DO next.
 *
 * Before 2026-08-31 it defaulted to booking_date DESC with an off-by-default
 * "Upcoming only" checkbox, so the top of the page was the booking furthest
 * into the future and today's work sat below months of them, interleaved with
 * everything already finished.
 *
 * ⚠ The clock is pinned. "Upcoming" is defined against today, so a test that
 * reads the real clock passes or fails depending on when it runs.
 */
class SevaBookingListOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-month so "yesterday" and "next month" stay inside sane ranges.
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAsAdmin(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'admin']);
        $user = AdminUser::create([
            'name' => 'List Order Admin',
            'email' => 'list-order-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh(), 'admin');
    }

    private function bookingOn(string $date): SevaBooking
    {
        return SevaBookingFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            // The list is paid-only by default.
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'booking_date' => $date,
            'status' => 'confirmed',
        ]);
    }

    public function test_it_opens_on_upcoming_bookings_soonest_first(): void
    {
        $this->actAsAdmin();

        $lastWeek = $this->bookingOn('2026-08-24');
        $today = $this->bookingOn('2026-08-31');
        $tomorrow = $this->bookingOn('2026-09-01');
        $nextMonth = $this->bookingOn('2026-10-15');

        Livewire::test(ListSevaBookings::class)
            ->assertOk()
            // Today first, then tomorrow, then next month.
            ->assertCanSeeTableRecords([$today, $tomorrow, $nextMonth], inOrder: true)
            // Anything already past is out of the way by default.
            ->assertCanNotSeeTableRecords([$lastWeek]);
    }

    public function test_a_seva_happening_later_today_still_counts_as_upcoming(): void
    {
        // The comparison is on the DATE, so a booking for this afternoon must
        // not drop off the list just because the clock has passed midnight.
        $this->actAsAdmin();

        $today = $this->bookingOn('2026-08-31');

        Livewire::test(ListSevaBookings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$today]);
    }

    public function test_the_past_option_shows_only_finished_sevas(): void
    {
        $this->actAsAdmin();

        $older = $this->bookingOn('2026-08-10');
        $recent = $this->bookingOn('2026-08-30');
        $today = $this->bookingOn('2026-08-31');

        Livewire::test(ListSevaBookings::class)
            ->filterTable('timeframe', 'past')
            ->assertOk()
            // Ordering follows the table's default (soonest first). A
            // ->reorder() inside the filter does not stick — Filament applies
            // defaultSort after filters — so the admin flips it by clicking
            // the Date header rather than it being decided here.
            ->assertCanSeeTableRecords([$recent, $older])
            ->assertCanNotSeeTableRecords([$today]);
    }

    public function test_the_all_option_shows_both(): void
    {
        $this->actAsAdmin();

        $past = $this->bookingOn('2026-08-10');
        $future = $this->bookingOn('2026-09-20');

        Livewire::test(ListSevaBookings::class)
            ->filterTable('timeframe', 'all')
            ->assertOk()
            ->assertCanSeeTableRecords([$past, $future]);
    }

    public function test_the_default_survives_the_filter_being_reopened(): void
    {
        // selectablePlaceholder(false): with a placeholder, clearing the
        // dropdown lands on an empty value that reads as "all" and silently
        // defeats the default. Falling back to upcoming is what keeps the
        // page honest.
        $this->actAsAdmin();

        $past = $this->bookingOn('2026-08-10');
        $future = $this->bookingOn('2026-09-20');

        Livewire::test(ListSevaBookings::class)
            ->filterTable('timeframe', '')
            ->assertOk()
            ->assertCanSeeTableRecords([$future])
            ->assertCanNotSeeTableRecords([$past]);
    }
}
