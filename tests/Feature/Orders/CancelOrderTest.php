<?php
// tests/Feature/Orders/CancelOrderTest.php

namespace Tests\Feature\Orders;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_a_pending_order_restores_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);

        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'pending_payment']);
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'product_sku'  => $product->sku,
            'quantity'     => 3,
            'price'        => $product->price,
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals(8, $product->fresh()->stock);
        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    public function test_cancelling_a_paid_wallet_order_refunds_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $product = Product::factory()->create(['stock' => 5]);

        $order = Order::factory()->create([
            'user_id'        => $user->id,
            'status'         => 'paid',
            'payment_method' => 'wallet',
            'total'          => 300,
        ]);
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'product_sku'  => $product->sku,
            'quantity'     => 1,
            'price'        => 300,
            'status'       => 'pending',
        ]);
        Payment::create([
            'order_id'               => $order->id,
            'gateway'                => 'wallet',
            'gateway_transaction_id' => 'wallet-test-123',
            'amount'                 => 300,
            'status'                 => 'paid',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals(300, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
    }

    public function test_cancelling_reverses_coupon_usage(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['used_count' => 5]);
        $product = Product::factory()->create(['stock' => 5]);

        $order = Order::factory()->create([
            'user_id'   => $user->id,
            'status'    => 'pending_payment',
            'coupon_id' => $coupon->id,
        ]);
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'product_sku'  => $product->sku,
            'quantity'     => 1,
            'price'        => $product->price,
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals(4, $coupon->fresh()->used_count);
    }

    public function test_cannot_cancel_an_order_already_being_processed(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $order = Order::factory()->create(['user_id' => $user->id]);
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'product_sku'  => $product->sku,
            'quantity'     => 1,
            'price'        => $product->price,
            'status'       => 'processing', // بدأ السيلر يجهزه خلاص
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(422);
    }

    public function test_another_users_order_cannot_be_cancelled(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(403);
    }
}