<?php

namespace Tests\Feature;

use App\Jobs\GenerateStoreInvoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Notifications\Drivers\WhatsAppNotificationDriver;
use Database\Factories\DevoteeFactory;
use Database\Factories\OrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Store-order item placeholders (2026-08-13).
 *
 * `item_count` counts order ROWS, so two of one product read as "1 item" in a
 * confirmation message — two real orders on production already showed the
 * mismatch (4 units reported as 3, 3 reported as 2). These placeholders give
 * template authors something that is actually true.
 *
 * The split between items_list and items_summary is not cosmetic: Meta rejects
 * a template parameter containing a newline, and nothing upstream stopped one
 * reaching the driver.
 */
class OrderItemPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    private function orderWith(array $lines): Order
    {
        $order = OrderFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
        ]);

        // product_id is NOT NULL; the label on the row is the snapshot, so
        // one real product backing every line is enough for these assertions.
        $product = \Database\Factories\ProductFactory::new()->create();

        foreach ($lines as [$qty, $name, $variant]) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $name,
                'variant_label' => $variant,
                'quantity' => $qty,
                'unit_price' => 100,
                'subtotal' => 100 * $qty,
            ]);
        }

        return $order->fresh()->load('items');
    }

    /** The exact case that prompted this: two of ONE product. */
    public function test_two_of_one_product_reads_as_two_not_one(): void
    {
        $order = $this->orderWith([[2, 'Shri Ladoo Prasad', null]]);

        // The old placeholder still counts rows — unchanged, and still wrong
        // for this sentence. That is why the new ones exist.
        $this->assertSame(1, $order->items->count());

        $this->assertSame(2, (int) $order->items->sum('quantity'));
        $this->assertSame('2 × Shri Ladoo Prasad', GenerateStoreInvoice::itemsList($order));
        $this->assertSame('2 × Shri Ladoo Prasad', GenerateStoreInvoice::itemsSummary($order));
    }

    /** Multiple products: one per line for email. */
    public function test_multiple_products_render_one_per_line(): void
    {
        $order = $this->orderWith([
            [2, 'શ્રી લાડુ પ્રસાદ', null],
            [1, 'હનુમાનજી ફોટો ફ્રેમ', null],
        ]);

        $this->assertSame(
            "2 × શ્રી લાડુ પ્રસાદ\n1 × હનુમાનજી ફોટો ફ્રેમ",
            GenerateStoreInvoice::itemsList($order),
        );

        // ...and on ONE line for WhatsApp.
        $this->assertSame(
            '2 × શ્રી લાડુ પ્રસાદ, 1 × હનુમાનજી ફોટો ફ્રેમ',
            GenerateStoreInvoice::itemsSummary($order),
        );
    }

    /** A variant is part of what the devotee bought, so it shows. */
    public function test_a_variant_is_named_on_the_line(): void
    {
        $order = $this->orderWith([[1, 'Mixed Sweet Box', '2 કિલો']]);

        $this->assertSame('1 × Mixed Sweet Box (2 કિલો)', GenerateStoreInvoice::itemsList($order));
    }

    /** A long order cannot blow past Meta's parameter length limit. */
    public function test_a_long_order_is_capped_on_the_whatsapp_line(): void
    {
        $order = $this->orderWith([
            [1, 'A', null], [1, 'B', null], [1, 'C', null],
            [1, 'D', null], [1, 'E', null], [1, 'F', null],
        ]);

        $summary = GenerateStoreInvoice::itemsSummary($order);

        $this->assertSame('1 × A, 1 × B, 1 × C, 1 × D +2 more', $summary);
        $this->assertStringNotContainsString("\n", $summary);
    }

    /**
     * THE GUARD. Even if a newline reaches the driver — a hand-written
     * template, a future placeholder, anything — the send must not die.
     */
    public function test_the_whatsapp_guard_flattens_anything_meta_would_reject(): void
    {
        $this->assertSame(
            '2 × Ladoo, 1 × Frame',
            WhatsAppNotificationDriver::flattenForWhatsApp("2 × Ladoo\n1 × Frame"),
        );

        // Tabs, blank lines and long space runs are rejected by Meta too.
        $this->assertSame('a, b', WhatsAppNotificationDriver::flattenForWhatsApp("a\t\tb"));
        $this->assertSame('a, b', WhatsAppNotificationDriver::flattenForWhatsApp("a\n\n\nb"));
        $this->assertSame('a b', WhatsAppNotificationDriver::flattenForWhatsApp('a     b'));

        // No leading/trailing separator litter.
        $this->assertSame('a', WhatsAppNotificationDriver::flattenForWhatsApp("\na\n"));
        $this->assertSame('', WhatsAppNotificationDriver::flattenForWhatsApp("\n\n"));
    }

    /** An empty order must not produce a stray separator. */
    public function test_an_order_with_no_items_renders_empty(): void
    {
        $order = $this->orderWith([]);

        $this->assertSame('', GenerateStoreInvoice::itemsList($order));
        $this->assertSame('', GenerateStoreInvoice::itemsSummary($order));
    }
}
