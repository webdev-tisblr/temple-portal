<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 3);
        $unit = fake()->randomFloat(2, 50, 1000);

        return [
            'order_id' => OrderFactory::new(),
            'product_id' => ProductFactory::new(),
            'product_name' => fake()->words(2, true),
            'variant_label' => null,
            'quantity' => $qty,
            'unit_price' => $unit,
            'subtotal' => $unit * $qty,
        ];
    }
}
