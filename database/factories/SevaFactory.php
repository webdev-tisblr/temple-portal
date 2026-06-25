<?php

namespace Database\Factories;

use App\Models\Seva;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Seva>
 */
class SevaFactory extends Factory
{
    protected $model = Seva::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name_gu' => $name,
            'name_hi' => $name,
            'name_en' => $name,
            'category' => 'puja',
            'price' => fake()->randomFloat(2, 51, 1100),
            'requires_booking' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
