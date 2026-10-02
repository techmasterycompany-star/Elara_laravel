<?php
// database/factories/SellerFactory.php

namespace Database\Factories;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SellerFactory extends Factory
{
    protected $model = Seller::class;

    public function definition(): array
    {
        $storeName = fake()->unique()->company();

        return [
            'user_id'    => User::factory()->state(['role' => 'seller']),
            'store_name' => $storeName,
            'store_slug' => str($storeName)->slug(),
            'description' => fake()->sentence(),
            'status'     => 'approved',   // الحالة الافتراضية approved عشان معظم الـ tests محتاجة seller شغال بسرعة
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }
}