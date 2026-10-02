<?php
// database/factories/SellerPayoutFactory.php

namespace Database\Factories;

use App\Models\Seller;
use App\Models\SellerPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

class SellerPayoutFactory extends Factory
{
    protected $model = SellerPayout::class;

    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'amount'    => fake()->randomFloat(2, 10, 500),
            'status'    => 'pending',
            'paid_at'   => null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);
    }
}
