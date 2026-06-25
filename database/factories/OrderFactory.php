<?php

namespace Database\Factories;

use App\Models\Devotee;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 3000);

        return [
            'order_number' => 'ORD-'.fake()->unique()->numerify('########'),
            'devotee_id' => DevoteeFactory::new(),
            'subtotal' => $subtotal,
            'shipping_charge' => 0,
            'total_amount' => $subtotal,
            // Pre-capture state — markCaptured() flips this to confirmed.
            'status' => 'pending',
            'shipping_name' => fake()->name(),
            'shipping_phone' => (string) fake()->numerify('9#########'),
            'shipping_address' => fake()->streetAddress(),
            'shipping_city' => fake()->city(),
            'shipping_state' => 'Gujarat',
            'shipping_pincode' => (string) fake()->numerify('######'),
        ];
    }

    public function forDevotee(Devotee $devotee): static
    {
        return $this->state(fn (array $attributes) => [
            'devotee_id' => $devotee->id,
        ]);
    }
}
