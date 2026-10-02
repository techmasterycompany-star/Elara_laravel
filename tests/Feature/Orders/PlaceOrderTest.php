<?php
// tests/Feature/Orders/PlaceOrderTest.php

namespace Tests\Feature\Orders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private function orderPayload(): array
    {
        return [
            'shipping_name'        => 'Test Customer',
            'shipping_phone'       => '01000000000',
            'shipping_street'      => '123 Test St',
            'shipping_city'        => 'Cairo',
            'shipping_governorate' => 'Cairo',
            'payment_method'       => 'cod',
        ];
    }

    public function test_authenticated_user_can_order_without_guest_email(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10, 'price' => 200]);

        $cart = $user->cart()->create();
        $cart->items()->create([
            'product_id'   => $product->id,
            'quantity'     => 2,
            'price_at_add' => $product->price,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/orders', $this->orderPayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'user_id'     => $user->id,
            'guest_email' => null,
        ]);
    }

    public function test_guest_requires_guest_email(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $cart = \App\Models\Cart::create(['session_id' => 'test-session-123']);
        $cart->items()->create([
            'product_id'   => $product->id,
            'quantity'     => 1,
            'price_at_add' => $product->price,
        ]);

        $response = $this->withHeaders(['X-Session-Id' => 'test-session-123'])
            ->postJson('/api/orders', $this->orderPayload());

        $response->assertStatus(422);
    }

    public function test_guest_can_order_with_guest_email(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $cart = \App\Models\Cart::create(['session_id' => 'test-session-456']);
        $cart->items()->create([
            'product_id'   => $product->id,
            'quantity'     => 1,
            'price_at_add' => $product->price,
        ]);

        $response = $this->withHeaders(['X-Session-Id' => 'test-session-456'])
            ->postJson('/api/orders', [
                ...$this->orderPayload(),
                'guest_email' => 'guest@example.com',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'user_id'     => null,
            'guest_email' => 'guest@example.com',
        ]);
    }

    public function test_order_fails_when_stock_insufficient(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $cart = $user->cart()->create();
        $cart->items()->create([
            'product_id'   => $product->id,
            'quantity'     => 5,
            'price_at_add' => $product->price,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(422);
    }

    public function test_order_decrements_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $cart = $user->cart()->create();
        $cart->items()->create([
            'product_id'   => $product->id,
            'quantity'     => 3,
            'price_at_add' => $product->price,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(201);

        $this->assertEquals(7, $product->fresh()->stock);
    }
}
