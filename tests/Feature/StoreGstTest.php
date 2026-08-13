<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Services\CounterEntryService;
use App\Services\StoreGstService;
use Database\Factories\DevoteeFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GST on store products (2026-08-13) — INCLUSIVE in the listed price and
 * opt-in PER PRODUCT.
 *
 * The per-product part is the whole reason this is not a copy of the hall
 * implementation. Prasad and seva-linked products must stay untaxed while a
 * taxable product sits in the same cart, which means the tax lives on the
 * line, not on the order.
 *
 * The invariant every case defends: decomposing a cart NEVER changes what it
 * costs. `subtotal` is identical with tax on or off; only its split moves.
 */
class StoreGstTest extends TestCase
{
    use RefreshDatabase;

    private function product(float $price, bool $gst = false, ?float $rate = null): Product
    {
        return ProductFactory::new()->create([
            'price' => $price,
            'gst_enabled' => $gst,
            'gst_rate' => $rate,
        ]);
    }

    private function setting(bool $enabled, string $rate = '18.00'): void
    {
        SystemSetting::updateOrCreate(['key' => 'store_gst_enabled'], ['value' => $enabled ? '1' : '0', 'group' => 'payment']);
        SystemSetting::updateOrCreate(['key' => 'store_gst_rate'], ['value' => $rate, 'group' => 'payment']);
        SystemSetting::forgetCache();
    }

    /** @param list<array{0: Product, 1: float}> $pairs */
    private function decompose(array $pairs): array
    {
        return app(StoreGstService::class)->decompose(array_map(
            fn (array $p): array => ['product' => $p[0], 'subtotal' => $p[1]],
            $pairs,
        ));
    }

    /** 1. A product that has not opted in is untaxed, switch or no switch. */
    public function test_a_product_without_the_toggle_is_never_taxed(): void
    {
        $this->setting(true);
        $result = $this->decompose([[$this->product(500), 500.0]]);

        // NULL, not 0.00 — "never taxed", not "taxed at zero percent".
        $this->assertNull($result['gst_amount']);
        $this->assertNull($result['taxable_amount']);
        $this->assertNull($result['lines'][0]['gst_rate']);
        $this->assertSame(500.0, $result['subtotal']);
    }

    /** 2. The master switch overrides an opted-in product. */
    public function test_the_master_switch_off_untaxes_everything(): void
    {
        $this->setting(false);
        $result = $this->decompose([[$this->product(500, gst: true), 500.0]]);

        $this->assertNull($result['gst_amount']);
    }

    /** 3. Tax is carved OUT of the listed price, never added to it. */
    public function test_gst_is_carved_out_of_the_listed_price(): void
    {
        $this->setting(true, '18.00');
        $result = $this->decompose([[$this->product(1180, gst: true), 1180.0]]);

        $this->assertSame(1180.0, $result['subtotal'], 'the listed price IS what is charged');
        $this->assertSame(1000.0, $result['taxable_amount']);
        $this->assertSame(180.0, $result['gst_amount']);
        $this->assertSame(18.0, $result['lines'][0]['gst_rate']);
    }

    /**
     * 4. THE case this design exists for: a taxed item and a seva-linked
     *    prasad item in one cart. The prasad must contribute nothing to the
     *    tax, and the cart total must not move.
     */
    public function test_a_mixed_cart_taxes_only_the_enabled_product(): void
    {
        $this->setting(true, '18.00');
        $result = $this->decompose([
            [$this->product(1180, gst: true), 1180.0],
            [$this->product(100), 300.0],   // prasad, 3 × 100
        ]);

        $this->assertSame(1480.0, $result['subtotal']);
        $this->assertSame(180.0, $result['gst_amount'], 'only the taxed line contributes');
        $this->assertSame(1000.0, $result['taxable_amount'], 'the prasad is in neither figure');

        $this->assertSame(18.0, $result['lines'][0]['gst_rate']);
        $this->assertNull($result['lines'][1]['gst_rate']);
    }

    /** 5. Two taxed products at different rates coexist in one order. */
    public function test_two_rates_in_one_cart_are_each_honoured(): void
    {
        $this->setting(true, '18.00');
        $result = $this->decompose([
            [$this->product(1180, gst: true), 1180.0],              // trust default 18%
            [$this->product(105, gst: true, rate: 5.0), 105.0],     // own override 5%
        ]);

        $this->assertSame(18.0, $result['lines'][0]['gst_rate']);
        $this->assertSame(5.0, $result['lines'][1]['gst_rate']);
        $this->assertSame(180.0 + 5.0, $result['gst_amount']);
        $this->assertSame(1285.0, $result['subtotal']);
    }

    /** 6. A product opted in while the trust rate is 0 stays untaxed. */
    public function test_a_zero_rate_reads_as_untaxed(): void
    {
        $this->setting(true, '0');
        $result = $this->decompose([[$this->product(500, gst: true), 500.0]]);

        $this->assertNull($result['lines'][0]['gst_rate']);
        $this->assertNull($result['gst_amount']);
    }

    /**
     * 7. Switching store GST on must not reprice a single cart. If this
     *    fails, the trust has silently raised its prices.
     */
    public function test_switching_gst_on_does_not_change_what_the_cart_costs(): void
    {
        $product = $this->product(999.99, gst: true);

        $this->setting(false);
        $without = $this->decompose([[$product, 2999.97]]);

        $this->setting(true, '18.00');
        $with = $this->decompose([[$product, 2999.97]]);

        $this->assertSame($without['subtotal'], $with['subtotal']);
    }

    /**
     * 9. END TO END through a real checkout path. The unit cases above prove
     *    the arithmetic; this proves a checkout actually PERSISTS it, which
     *    is the part an invoice reads. Counter entry is used because it is
     *    the one path that needs no Razorpay round trip — it shares
     *    StoreGstService with the web and API checkouts.
     */
    public function test_a_counter_sale_persists_the_tax_onto_the_order_and_its_lines(): void
    {
        $this->setting(true, '18.00');

        $devotee = DevoteeFactory::new()->create(['phone' => '9876500099']);
        $taxed = $this->product(1180, gst: true);
        $prasad = $this->product(100);

        $admin = AdminUser::create([
            'name' => 'Counter Clerk',
            'email' => 'counter-gst@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(CounterEntryService::class)->record([
            'entry_token' => CounterEntryService::newEntryToken(),
            'payment_method' => 'cash',
            'paid_on' => now()->toDateString(),
            'record_type' => CounterEntryService::TYPE_STORE,
            'phone' => $devotee->phone,
            'items' => [
                ['product_id' => $taxed->id, 'quantity' => 1],
                ['product_id' => $prasad->id, 'quantity' => 3],
            ],
        ], $admin);

        $order = Order::firstOrFail();

        // The cash taken is the shelf price of both items, tax already inside.
        $this->assertSame('1480.00', (string) $order->total_amount);
        $this->assertSame('180.00', (string) $order->gst_amount);
        $this->assertSame('1000.00', (string) $order->taxable_amount);

        $lines = OrderItem::orderBy('id')->get();
        $this->assertSame('18.00', (string) $lines[0]->gst_rate);
        $this->assertSame('180.00', (string) $lines[0]->gst_amount);
        $this->assertNull($lines[1]->gst_rate, 'the prasad line carries no tax');
        $this->assertNull($lines[1]->gst_amount);
    }

    /** 8. Taxable + GST reconstitute the gross exactly, at awkward rates. */
    public function test_the_split_reconstitutes_the_gross_to_the_paisa(): void
    {
        foreach ([['5.00', 1001.50], ['12.50', 3333.33], ['18.00', 7777.77], ['2.50', 999.99]] as [$rate, $gross]) {
            $this->setting(true, $rate);
            $result = $this->decompose([[$this->product($gross, gst: true), $gross]]);

            $this->assertEqualsWithDelta(
                $result['subtotal'],
                round($result['taxable_amount'] + $result['gst_amount'], 2),
                0.001,
                "taxable + GST must equal the gross at {$rate}% on {$gross}",
            );

            // And the invoice's CGST/SGST split must reconstitute gst_amount
            // exactly — SGST is the remainder, never a second rounding.
            $cgst = round($result['gst_amount'] / 2, 2);
            $sgst = round($result['gst_amount'] - $cgst, 2);
            $this->assertEqualsWithDelta($result['gst_amount'], $cgst + $sgst, 0.001);
        }
    }
}
