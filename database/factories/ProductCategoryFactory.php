<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name_gu' => $name,
            'name_hi' => $name,
            'name_en' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
