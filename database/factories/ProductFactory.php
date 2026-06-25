<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'category_id' => ProductCategoryFactory::new(),
            'name_gu' => $name,
            'name_hi' => $name,
            'name_en' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'price' => fake()->randomFloat(2, 50, 2000),
            'stock_quantity' => 100,
            'is_active' => true,
            'is_featured' => false,
            'has_variants' => false,
            'sort_order' => 0,
        ];
    }
}
