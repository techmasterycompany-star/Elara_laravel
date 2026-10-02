<?php
// database/factories/CouponFactory.php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code'           => strtoupper(fake()->unique()->bothify('SAVE##??')),
            'discount_type'  => 'fixed',
            'discount_value' => 50,
            'expires_at'     => now()->addMonth(),
            'usage_limit'    => 100,
            'used_count'     => 0,
        ];
    }

    public function percent(int $value = 10): static
    {
        return $this->state([
            'discount_type'  => 'percent',
            'discount_value' => $value,
        ]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function maxedOut(): static
    {
        return $this->state(fn (array $attrs) => ['used_count' => $attrs['usage_limit'] ?? 100]);
    }
}