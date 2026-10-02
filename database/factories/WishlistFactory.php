<?php
// database/factories/WishlistFactory.php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Factories\Factory;

class WishlistFactory extends Factory
{
    protected $model = Wishlist::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'product_id' => Product::factory(),
        ];
    }
}
