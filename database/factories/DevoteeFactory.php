<?php

namespace Database\Factories;

use App\Models\Devotee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Devotee>
 */
class DevoteeFactory extends Factory
{
    protected $model = Devotee::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Unique numeric phone within the 10-digit column width.
            'phone' => (string) fake()->unique()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'state' => 'Gujarat',
            'country' => 'India',
            'language' => 'gu',
            'is_active' => true,
        ];
    }
}
