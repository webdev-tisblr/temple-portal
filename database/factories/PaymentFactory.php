<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'razorpay_order_id' => 'order_'.fake()->unique()->bothify('??##########'),
            'razorpay_payment_id' => null,
            'amount' => fake()->randomFloat(2, 100, 5000),
            'currency' => 'INR',
            // Default to a pre-capture status so markCaptured() has work to do.
            'status' => 'created',
            'description' => 'Test payment',
        ];
    }

    public function captured(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'captured',
            'razorpay_payment_id' => 'pay_'.fake()->bothify('??##########'),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}
