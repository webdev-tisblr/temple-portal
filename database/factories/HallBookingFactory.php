<?php

namespace Database\Factories;

use App\Models\Hall;
use App\Models\HallBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HallBooking>
 */
class HallBookingFactory extends Factory
{
    protected $model = HallBooking::class;

    public function definition(): array
    {
        $start = now()->addDays(10)->toDateString();

        return [
            'devotee_id' => DevoteeFactory::new(),
            'hall_id' => HallFactory::new(),
            // booking_date is the RANGE START; end_date defaults to the
            // same day, i.e. a single-day booking.
            'booking_date' => $start,
            'end_date' => $start,
            'days_count' => 1,
            'booking_type' => 'full_day',
            'purpose' => 'Wedding',
            'contact_name' => fake()->name(),
            'contact_phone' => (string) fake()->numerify('9#########'),
            'total_amount' => 5000.00,
            'status' => 'pending',
        ];
    }

    public function forHall(Hall $hall): static
    {
        return $this->state(fn (array $attributes) => ['hall_id' => $hall->id]);
    }

    public function range(string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_date' => $start,
            'end_date' => $end,
            'days_count' => \Illuminate\Support\Carbon::parse($start)->diffInDays(\Illuminate\Support\Carbon::parse($end)) + 1,
        ]);
    }
}
