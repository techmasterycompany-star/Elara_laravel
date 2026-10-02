<?php
// database/factories/ProductFactory.php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'seller_id'   => null,   // null = admin-owned بشكل افتراضي (زي الاتفاق الأصلي في المشروع)
            'name'        => ucfirst($name),
            'slug'        => str($name)->slug() . '-' . fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->paragraph(),
            'price'       => fake()->randomFloat(2, 50, 2000),
            'sale_price'  => null,
            'stock'       => fake()->numberBetween(5, 100),
            'sku'         => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'status'      => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }

    public function onSale(): static
    {
        return $this->state(fn (array $attrs) => [
            'sale_price' => round($attrs['price'] * 0.8, 2),
        ]);
    }
}