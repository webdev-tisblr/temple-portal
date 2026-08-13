<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ProductFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deleting store orders from the admin (2026-08-13).
 *
 * The load-bearing rule: an order goes, its line items go with it, and the
 * PAYMENT STAYS. The payment is the money record — for a real order it has
 * to survive for audit and reconciliation, and the Razorpay webhook history
 * references it. Clearing payments is a separate, deliberate act.
 */
class OrderDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $payment = PaymentFactory::new()->create();
        $order = Order::create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => $payment->id,
            'order_number' => 'ORD-TEST-'.uniqid(),
            'subtotal' => 500,
            'shipping_charge' => 0,
            'total_amount' => 500,
            'status' => 'confirmed',
            'shipping_name' => 'Ramesh',
            'shipping_phone' => '9876543210',
            'shipping_address' => 'Antarjal',
            'shipping_city' => 'Gandhidham',
            'shipping_state' => 'Gujarat',
            'shipping_pincode' => '370205',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => ProductFactory::new()->create()->id,
            'product_name' => 'Shree Ladoo Prasad',
            'quantity' => 2,
            'unit_price' => 250,
            'subtotal' => 500,
        ]);

        return $order;
    }

    private function admin(array $permissions): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Store Admin',
            'email' => 'store-'.uniqid().'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $role = Role::findOrCreate('store_test_'.uniqid(), 'admin');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'admin'));
        }
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }

    public function test_deleting_an_order_removes_its_lines_but_keeps_the_payment(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $order = $this->order();
        $paymentId = $order->payment_id;

        $this->actingAs($this->admin(['view_any_order', 'delete_order']), 'admin');

        Livewire::test(ListOrders::class)
            ->callTableAction('delete', $order)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('temple_orders', ['id' => $order->id]);
        // Cascaded by the FK, not by application code.
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->count());
        $this->assertNotNull(Payment::find($paymentId), 'the money record must survive');
    }

    public function test_orders_can_be_deleted_in_bulk(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $first = $this->order();
        $second = $this->order();

        $this->actingAs($this->admin(['view_any_order', 'delete_any_order']), 'admin');

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('delete', [$first, $second])
            ->assertHasNoErrors();

        $this->assertSame(0, Order::count());
        $this->assertSame(2, Payment::count(), 'both money records must survive');
    }

    /**
     * Read-only staff must not see the button at all. Filament authorises
     * DeleteAction against OrderPolicy, so this is really asserting the
     * policy is wired — an unauthorised action that merely fails on click
     * would still have advertised itself.
     */
    public function test_an_admin_without_the_permission_cannot_delete(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $order = $this->order();

        $this->actingAs($this->admin(['view_any_order']), 'admin');

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('delete', $order);

        $this->assertDatabaseHas('temple_orders', ['id' => $order->id]);
    }
}
