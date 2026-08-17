<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\CounterEntryService;
use Database\Factories\ProductFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A seva that hands over a prasad/vastra consumes stock, exactly as a store
 * sale does (2026-08-17). Before this only store orders decremented, so the
 * same item could be booked through sevas forever and the shelf count never
 * moved — which also meant it never went out of stock and so never
 * disappeared from the seva, however many were actually given away.
 *
 * Driven through the counter path because it funnels into the same
 * PaymentCaptureService::markCaptured() every online payment does.
 */
class SevaBookingStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Counter Clerk',
            'email' => 'stock-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    /** @param array<string,mixed> $overrides */
    private function book(array $overrides): array
    {
        return app(CounterEntryService::class)->record(array_merge([
            'entry_token' => CounterEntryService::newEntryToken(),
            'payment_method' => 'cash',
            'paid_on' => now()->toDateString(),
            'record_type' => CounterEntryService::TYPE_SEVA,
            'phone' => '98765000'.random_int(10, 99),
            'devotee_name' => 'Walk In',
            'booking_date' => now()->addDays(4)->toDateString(),
            'quantity' => 1,
        ], $overrides), $this->admin());
    }

    private function sevaWith(Product $product): Seva
    {
        return SevaFactory::new()->create([
            'price' => 101,
            'requires_booking' => true,
            'is_variable_price' => false,
            'linked_products' => ['type' => 'products', 'product_ids' => [$product->id]],
        ]);
    }

    public function test_booking_a_seva_decrements_the_products_stock(): void
    {
        $product = ProductFactory::new()->create(['price' => 251, 'stock_quantity' => 5]);
        $seva = $this->sevaWith($product);

        $this->book(['seva_id' => $seva->id, 'selected_product_id' => $product->id, 'quantity' => 2]);

        $this->assertSame(3, $product->fresh()->stock_quantity, '5 − 2 booked');
    }

    public function test_booking_decrements_the_chosen_variant_only(): void
    {
        $product = ProductFactory::new()->create([
            'has_variants' => true,
            'track_stock' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 251, 'stock' => 4],
                ['label' => 'Large', 'price' => 1101, 'stock' => 3],
            ],
        ]);
        $seva = $this->sevaWith($product);

        $this->book([
            'seva_id' => $seva->id,
            'selected_product_id' => $product->id,
            'selected_variant_label' => 'Large',
        ]);

        $variants = collect($product->fresh()->variants)->keyBy('label');
        $this->assertSame(2, (int) $variants['Large']['stock'], 'Large 3 − 1');
        $this->assertSame(4, (int) $variants['Small']['stock'], 'Small untouched');
    }

    public function test_an_untracked_product_is_not_decremented(): void
    {
        $product = ProductFactory::new()->create([
            'price' => 251,
            'track_stock' => false,
            'stock_quantity' => 0,
        ]);
        $seva = $this->sevaWith($product);

        $this->book(['seva_id' => $seva->id, 'selected_product_id' => $product->id]);

        $this->assertSame(0, $product->fresh()->stock_quantity, 'unlimited stock never moves');
    }

    public function test_the_last_unit_takes_the_product_out_of_the_seva(): void
    {
        // The whole point: selling the last one must make it disappear from
        // the seva, which only works if the booking actually decremented.
        $product = ProductFactory::new()->create(['price' => 251, 'stock_quantity' => 1]);
        $seva = $this->sevaWith($product);

        $this->assertTrue($seva->getLinkedProductsList()->contains('id', $product->id));

        $this->book(['seva_id' => $seva->id, 'selected_product_id' => $product->id]);

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertFalse(
            $seva->fresh()->getLinkedProductsList()->contains('id', $product->id),
            'a sold-out product must drop out of the seva'
        );
    }

    public function test_a_seva_without_a_product_touches_no_stock(): void
    {
        $product = ProductFactory::new()->create(['stock_quantity' => 5]);
        $seva = SevaFactory::new()->create([
            'price' => 101,
            'requires_booking' => true,
            'is_variable_price' => false,
            'linked_products' => null,
        ]);

        $result = $this->book(['seva_id' => $seva->id]);

        $this->assertNotNull(SevaBooking::where('payment_id', $result['payment']->id)->first());
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }
}
