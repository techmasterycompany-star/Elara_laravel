<?php
// database/factories/OrderFactory.php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 1500);
        $shippingFee = 100;

        return [
            'order_number'         => 'GT-' . fake()->unique()->numberBetween(10000, 99999),
            'user_id'              => User::factory(),
            'coupon_id'            => null,
            'shipping_name'        => fake()->name(),
            'shipping_phone'       => fake()->phoneNumber(),
            'shipping_street'      => fake()->streetAddress(),
            'shipping_city'        => fake()->city(),
            'shipping_governorate' => fake()->state(),
            'guest_email'          => null,
            'status'               => 'pending_payment',
            'subtotal'             => $subtotal,
            'discount'             => 0,
            'shipping_fee'         => $shippingFee,
            'total'                => $subtotal + $shippingFee,
            'payment_method'       => 'cod',
        ];
    }

    public function guest(): static
    {
        return $this->state([
            'user_id'     => null,
            'guest_email' => fake()->unique()->safeEmail(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}