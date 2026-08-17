<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\SevaResource;
use Database\Factories\DevoteeFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A product with no inventory must not be offerable as a seva option
 * (2026-08-17). Seva::getLinkedProductsList() is the single gate — these
 * assert it holds for every stock shape and that the website + API payload
 * inherit it.
 */
class SevaLinkedProductStockTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,mixed> $productAttributes */
    private function sevaWithLinkedProduct(array $productAttributes): array
    {
        $product = ProductFactory::new()->create($productAttributes);
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'linked_products' => ['type' => 'products', 'product_ids' => [$product->id]],
        ]);

        return [$seva, $product];
    }

    public function test_a_zero_stock_product_is_not_offered(): void
    {
        [$seva] = $this->sevaWithLinkedProduct(['stock_quantity' => 0, 'track_stock' => true]);

        $this->assertTrue($seva->hasProductSelection(), 'the seva is still configured for selection');
        $this->assertCount(0, $seva->getLinkedProductsList());
    }

    public function test_a_stocked_product_is_offered(): void
    {
        [$seva, $product] = $this->sevaWithLinkedProduct(['stock_quantity' => 5, 'track_stock' => true]);

        $this->assertSame([$product->id], $seva->getLinkedProductsList()->pluck('id')->all());
    }

    public function test_an_untracked_product_is_offered_even_at_zero(): void
    {
        // track_stock=false means "unlimited"; stock_quantity is meaningless.
        [$seva, $product] = $this->sevaWithLinkedProduct(['stock_quantity' => 0, 'track_stock' => false]);

        $this->assertSame([$product->id], $seva->getLinkedProductsList()->pluck('id')->all());
    }

    public function test_a_variable_product_survives_while_one_variant_has_stock(): void
    {
        [$seva, $product] = $this->sevaWithLinkedProduct([
            'has_variants' => true,
            'track_stock' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 101, 'stock' => 0],
                ['label' => 'Large', 'price' => 251, 'stock' => 3],
            ],
        ]);

        $this->assertSame([$product->id], $seva->getLinkedProductsList()->pluck('id')->all());
    }

    public function test_a_variable_product_with_every_variant_sold_out_is_dropped(): void
    {
        [$seva] = $this->sevaWithLinkedProduct([
            'has_variants' => true,
            'track_stock' => true,
            'variants' => [
                ['label' => 'Small', 'price' => 101, 'stock' => 0],
                ['label' => 'Large', 'price' => 251, 'stock' => 0],
            ],
        ]);

        $this->assertCount(0, $seva->getLinkedProductsList());
    }

    public function test_only_stocked_products_reach_the_seva_page(): void
    {
        $inStock = ProductFactory::new()->create(['stock_quantity' => 4, 'track_stock' => true]);
        $soldOut = ProductFactory::new()->create(['stock_quantity' => 0, 'track_stock' => true]);
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'linked_products' => [
                'type' => 'products',
                'product_ids' => [$inStock->id, $soldOut->id],
            ],
        ]);

        $html = $this->actingAs(DevoteeFactory::new()->create(), 'devotee')
            ->get('/seva/'.$seva->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($inStock->name_gu, $html);
        $this->assertStringNotContainsString($soldOut->name_gu, $html);
        // The tile's click handler carries the id — the surest proof the
        // sold-out product is unselectable rather than merely unnamed.
        $this->assertStringContainsString('selectedProductId = '.$inStock->id.';', $html);
        $this->assertStringNotContainsString('selectedProductId = '.$soldOut->id.';', $html);
    }

    public function test_a_fully_sold_out_seva_shows_the_notice_instead_of_the_form(): void
    {
        [$seva] = $this->sevaWithLinkedProduct(['stock_quantity' => 0, 'track_stock' => true]);

        $html = $this->actingAs(DevoteeFactory::new()->create(), 'devotee')
            ->get('/seva/'.$seva->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('seva.products_unavailable'), $html);
        $this->assertStringNotContainsString('name="selected_product_id"', $html);
    }

    public function test_booking_a_fully_sold_out_seva_is_rejected(): void
    {
        [$seva, $product] = $this->sevaWithLinkedProduct(['stock_quantity' => 0, 'track_stock' => true]);

        $this->actingAs(DevoteeFactory::new()->create(), 'devotee')
            ->from('/seva/'.$seva->id)
            ->post('/seva/'.$seva->id.'/book', [
                'booking_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
                'selected_product_id' => $product->id,
            ])
            ->assertSessionHasErrors('selected_product_id');

        $this->assertDatabaseCount('temple_seva_bookings', 0);
    }

    public function test_the_api_payload_omits_sold_out_products(): void
    {
        $inStock = ProductFactory::new()->create(['stock_quantity' => 4, 'track_stock' => true]);
        $soldOut = ProductFactory::new()->create(['stock_quantity' => 0, 'track_stock' => true]);
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'linked_products' => [
                'type' => 'products',
                'product_ids' => [$inStock->id, $soldOut->id],
            ],
        ]);

        $payload = (new SevaResource($seva))->toArray(Request::create('/'));

        $this->assertSame(
            [$inStock->id],
            array_column($payload['product_selection']['products'], 'id')
        );
    }

    public function test_the_api_drops_product_selection_when_nothing_is_in_stock(): void
    {
        [$seva] = $this->sevaWithLinkedProduct(['stock_quantity' => 0, 'track_stock' => true]);

        $payload = (new SevaResource($seva))->toArray(Request::create('/'));

        $this->assertNull($payload['product_selection']);
        $this->assertNull($payload['starts_from']);
    }
}
