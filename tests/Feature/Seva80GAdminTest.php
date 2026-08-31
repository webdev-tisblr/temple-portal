<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\SevaBookingResource\Pages\EditSevaBooking;
use App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings;
use App\Models\AdminUser;
use App\Services\ReceiptService;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seva 80G admin surface actually renders.
 *
 * Filament 3 resolves closure parameters by type-hint or canonical name
 * only, so `fn ($q) => ...` throws BindingResolutionException at RENDER
 * time — a class of bug that no amount of `php -l` catches and that takes
 * the live page down. These tests mount the real Livewire components.
 */
class Seva80GAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        $suffix = Str::lower(Str::random(8));

        // The AuthServiceProvider Gate::before short-circuits every check
        // for this role, which is what we want here: the subject is
        // rendering, not authorization (AdminAuthorizationTest owns that).
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'admin']);

        $user = AdminUser::create([
            'name' => "Test Admin {$suffix}",
            'email' => "admin-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_bookings_list_renders_with_the_80g_column_and_filters(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $withPan = SevaBookingFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->withPan()->create()->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);
        app(ReceiptService::class)->generateForSevaBooking($withPan);

        // Asked but refused — the row the "missing receipt" filter is for.
        SevaBookingFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);

        Livewire::test(ListSevaBookings::class)
            ->assertOk()
            // Both filters must EXECUTE, not merely exist: a bad closure
            // signature only throws once the query is built.
            ->filterTable('wants_80g', true)
            ->assertOk()
            ->resetTableFilters()
            ->filterTable('missing_80g_receipt', true)
            ->assertOk();
    }

    public function test_the_edit_page_renders_and_labels_the_80g_receipt(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->withPan()->create()->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);
        $receipt = app(ReceiptService::class)->generateForSevaBooking($booking);

        Livewire::test(EditSevaBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            // The statutory number is what an admin needs to quote back.
            ->assertSee($receipt->receipt_number)
            ->assertActionVisible('download_receipt');
    }

    public function test_the_edit_page_names_why_a_receipt_is_missing(): void
    {
        // Asked for, refused for want of a PAN. An admin looking at this
        // booking must be able to tell that apart from "never asked".
        $this->actingAs($this->superAdmin(), 'admin');

        $booking = SevaBookingFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            'wants_80g' => true,
        ]);

        Livewire::test(EditSevaBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee('no valid PAN');
    }
}
