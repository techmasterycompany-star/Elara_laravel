<?php
// database/factories/OrderItemFactory.php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id'     => Order::factory(),
            'product_id'   => Product::factory(),
            'seller_id'    => null,
            'product_name' => fake()->words(3, true),
            'product_sku'  => strtoupper(fake()->unique()->bothify('SKU-####')),
            'quantity'     => fake()->numberBetween(1, 5),
            'price'        => fake()->randomFloat(2, 50, 500),
            'status'       => 'pending',
        ];
    }

    public function delivered(): static
    {
        return $this->state(['status' => 'delivered']);
    }
}