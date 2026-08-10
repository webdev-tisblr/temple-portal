<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Factories\DevoteeFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hover-zoom panel lives inside the devotee-only booking picker, so a
 * guest render can never prove it exists. This asserts the wiring for a
 * logged-in devotee looking at a seva that actually has linked products.
 */
class SevaZoomRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_hover_zoom_markup_renders_for_a_seva_with_products(): void
    {
        // linked_products is a CONFIG blob, not a bare id list, and the
        // product must be active or getLinkedProductsList() filters it out.
        $product = ProductFactory::new()->create([
            'image_path' => 'products/test.jpg',
            'is_active' => true,
        ]);
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'linked_products' => ['type' => 'products', 'product_ids' => [$product->id]],
        ]);

        $html = $this->actingAs(DevoteeFactory::new()->create(), 'devotee')
            ->get('/seva/'.$seva->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('seva-zoom-panel', $html, 'the zoom panel is missing');
        $this->assertStringContainsString('zoomMove($event)', $html, 'the cursor tracker is not wired');
        $this->assertStringContainsString('background-position:${zoomBg}', $html, 'the magnifier is not cursor-driven');
        $this->assertStringContainsString('pointer-events-none', $html, 'the panel would steal its own hover');
    }

    public function test_a_seva_without_products_renders_no_zoom_hooks(): void
    {
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'linked_products' => null,
        ]);

        $html = $this->actingAs(DevoteeFactory::new()->create(), 'devotee')
            ->get('/seva/'.$seva->id)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('zoomMove($event)', $html);
    }
}
