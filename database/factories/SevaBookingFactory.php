<?php

namespace Database\Factories;

use App\Models\Devotee;
use App\Models\Seva;
use App\Models\SevaBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SevaBooking>
 */
class SevaBookingFactory extends Factory
{
    protected $model = SevaBooking::class;

    public function definition(): array
    {
        return [
            'devotee_id' => DevoteeFactory::new(),
            'seva_id' => SevaFactory::new(),
            'booking_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
            'total_amount' => fake()->randomFloat(2, 51, 1100),
            // Pre-capture state — markCaptured() flips this to confirmed.
            'status' => 'pending',
        ];
    }

    public function forDevotee(Devotee $devotee): static
    {
        return $this->state(fn (array $attributes) => [
            'devotee_id' => $devotee->id,
        ]);
    }

    public function forSeva(Seva $seva): static
    {
        return $this->state(fn (array $attributes) => [
            'seva_id' => $seva->id,
        ]);
    }
}
