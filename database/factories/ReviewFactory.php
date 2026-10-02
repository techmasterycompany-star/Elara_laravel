<?php
// database/factories/ReviewFactory.php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id'    => User::factory(),
            'rating'     => fake()->numberBetween(1, 5),
            'comment'    => fake()->sentence(),
        ];
    }
}