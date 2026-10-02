<?php
// database/factories/CartFactory.php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'session_id' => null,
            'coupon_id'  => null,
        ];
    }

    /** كارت ضيف: من غير يوزر ومربوط بـ X-Session-Id */
    public function guest(?string $sessionId = null): static
    {
        return $this->state([
            'user_id'    => null,
            'session_id' => $sessionId ?? (string) Str::uuid(),
        ]);
    }
}
