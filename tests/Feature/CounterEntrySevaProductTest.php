<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SevaBooking;
use App\Services\CounterEntryService;
use Database\Factories\ProductFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Counter entry for a seva that carries a product choice (2026-08-17).
 *
 * The service has always priced and persisted selected_product_id /
 * selected_variant_label, but the counter form had no picker for them — so a
 * walk-in booking of a product-linked seva recorded no product and silently
 * charged the seva's own price instead of the product's. These lock in the
 * price actually taken at the counter, which is the part that costs money.
 */
class CounterEntrySevaProductTest extends TestCase
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
            'email' => 'counter-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    /** @param array<string,mixed> $overrides */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'entry_token' => CounterEntryService::newEntryToken(),
            'payment_method' => 'cash',
            'paid_on' => now()->toDateString(),
            'record_type' => CounterEntryService::TYPE_SEVA,
            'phone' => '9876500077',
            'devotee_name' => 'Walk In Devotee',
            'booking_date' => now()->addDays(4)->toDateString(),
            'quantity' => 1,
        ], $overrides);
    }

    /** @param array<string,mixed> $productAttributes */
    private function sevaWithProduct(array $productAttributes): array
    {
        $product = ProductFactory::new()->create($productAttributes);
        $seva = SevaFactory::new()->create([
            'price' => 101,
            'requires_booking' => true,
            'is_variable_price' => false,
            'linked_products' => ['type' => 'products', 'product_ids' => [$product->id]],
        ]);

        return [$seva, $product];
    }

    public function test_the_counter_charges_the_chosen_products_price(): void
    {
        [$seva, $product] = $this->sevaWithProduct(['price' => 551, 'stock_quantity' => 5]);

        $result = app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
            'selected_product_id' => $product->id,
        ]), $this->admin());

        $booking = SevaBooking::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertSame($product->id, $booking->selected_product_id);
        $this->assertEqualsWithDelta(551.0, (float) $booking->total_amount, 0.001,
            "the product's price must win over the seva's own 101");
    }

    public function test_the_counter_charges_the_chosen_variants_price(): void
    {
        [$seva, $product] = $this->sevaWithProduct([
            'has_variants' => true,
            'track_stock' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 251, 'stock' => 4],
                ['label' => 'Large', 'price' => 1101, 'stock' => 2],
            ],
        ]);

        $result = app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
            'selected_product_id' => $product->id,
            'selected_variant_label' => 'Large',
            'quantity' => 2,
        ]), $this->admin());

        $booking = SevaBooking::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertSame('Large', $booking->selected_variant_label);
        $this->assertEqualsWithDelta(2202.0, (float) $booking->total_amount, 0.001, '1101 × 2');
    }

    public function test_a_product_linked_seva_cannot_be_booked_without_a_product(): void
    {
        [$seva] = $this->sevaWithProduct(['price' => 551, 'stock_quantity' => 5]);

        $this->expectException(ValidationException::class);

        app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
        ]), $this->admin());
    }

    public function test_a_product_from_another_seva_is_refused(): void
    {
        [$seva] = $this->sevaWithProduct(['price' => 551, 'stock_quantity' => 5]);
        $foreign = ProductFactory::new()->create(['price' => 11, 'stock_quantity' => 5]);

        $this->expectException(ValidationException::class);

        app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
            'selected_product_id' => $foreign->id,
        ]), $this->admin());
    }

    public function test_a_sold_out_product_is_refused_at_the_counter(): void
    {
        // Sold out between the clerk opening the form and taking the money.
        [$seva, $product] = $this->sevaWithProduct(['price' => 551, 'stock_quantity' => 0]);

        $this->expectException(ValidationException::class);

        app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
            'selected_product_id' => $product->id,
        ]), $this->admin());
    }

    public function test_a_sold_out_variant_is_refused_at_the_counter(): void
    {
        [$seva, $product] = $this->sevaWithProduct([
            'has_variants' => true,
            'track_stock' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 251, 'stock' => 4],
                ['label' => 'Large', 'price' => 1101, 'stock' => 0],
            ],
        ]);

        $this->expectException(ValidationException::class);

        app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
            'selected_product_id' => $product->id,
            'selected_variant_label' => 'Large',
        ]), $this->admin());
    }

    public function test_a_seva_without_a_product_choice_is_unaffected(): void
    {
        $seva = SevaFactory::new()->create([
            'price' => 101,
            'requires_booking' => true,
            'is_variable_price' => false,
            'linked_products' => null,
        ]);

        $result = app(CounterEntryService::class)->record($this->entry([
            'seva_id' => $seva->id,
        ]), $this->admin());

        $booking = SevaBooking::where('payment_id', $result['payment']->id)->firstOrFail();

        $this->assertNull($booking->selected_product_id);
        $this->assertEqualsWithDelta(101.0, (float) $booking->total_amount, 0.001);
    }
}
