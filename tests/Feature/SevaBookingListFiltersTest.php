<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings;
use App\Models\AdminUser;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seva bookings list defaults to paid bookings only (2026-08-17).
 *
 * A booking sits at `pending` while the devotee is inside Razorpay and is
 * flipped to `cancelled` by bookings:clean-stale when they never come back —
 * so the list was padded with abandoned checkouts the temple must not prepare
 * anything for.
 */
class SevaBookingListFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = AdminUser::create([
            'name' => 'List Admin',
            'email' => 'list-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh(), 'admin');
    }

    public function test_abandoned_and_cancelled_bookings_are_hidden_by_default(): void
    {
        $confirmed = SevaBookingFactory::new()->create(['status' => 'confirmed']);
        $completed = SevaBookingFactory::new()->create(['status' => 'completed']);
        // Money genuinely moved and came back — this must stay visible.
        $refunded = SevaBookingFactory::new()->create(['status' => 'refunded']);
        $pending = SevaBookingFactory::new()->create(['status' => 'pending']);
        $cancelled = SevaBookingFactory::new()->create(['status' => 'cancelled']);

        Livewire::test(ListSevaBookings::class)
            ->assertCanSeeTableRecords([$confirmed, $completed, $refunded])
            ->assertCanNotSeeTableRecords([$pending, $cancelled]);
    }

    public function test_unticking_the_filter_reveals_them(): void
    {
        // The abandoned rows are hidden, not gone — an operator chasing a
        // devotee's failed payment still has to be able to find one.
        $cancelled = SevaBookingFactory::new()->create(['status' => 'cancelled']);

        Livewire::test(ListSevaBookings::class)
            ->assertCanNotSeeTableRecords([$cancelled])
            ->filterTable('paid_only', false)
            ->assertCanSeeTableRecords([$cancelled]);
    }

    public function test_the_seva_filter_isolates_one_seva(): void
    {
        $flag = SevaFactory::new()->create(['name_gu' => 'ધ્વજા સેવા']);
        $shringar = SevaFactory::new()->create(['name_gu' => 'શૃંગાર સેવા']);

        $onFlag = SevaBookingFactory::new()->create(['seva_id' => $flag->id, 'status' => 'confirmed']);
        $onShringar = SevaBookingFactory::new()->create(['seva_id' => $shringar->id, 'status' => 'confirmed']);

        Livewire::test(ListSevaBookings::class)
            ->filterTable('seva_id', $flag->id)
            ->assertCanSeeTableRecords([$onFlag])
            ->assertCanNotSeeTableRecords([$onShringar]);
    }
}
