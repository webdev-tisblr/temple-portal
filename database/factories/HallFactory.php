<?php

namespace Database\Factories;

use App\Models\Hall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hall>
 */
class HallFactory extends Factory
{
    protected $model = Hall::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            // `name` is the legacy single column the localized accessor
            // falls back to; name_gu is what the admin actually fills.
            'name' => $name,
            'name_gu' => $name,
            'name_hi' => $name,
            'name_en' => $name,
            'capacity' => 300,
            'price_per_day' => 5000.00,
            // Defaults mirror the migration: single-day, no cut-off.
            'max_booking_days' => 1,
            'booking_cutoff_hours' => 0,
            'day_start_time' => '09:00:00',
            'is_active' => true,
        ];
    }

    public function multiDay(int $maxDays = 7): static
    {
        return $this->state(fn (array $attributes) => ['max_booking_days' => $maxDays]);
    }

    public function withCutoff(int $hours): static
    {
        return $this->state(fn (array $attributes) => ['booking_cutoff_hours' => $hours]);
    }
}
