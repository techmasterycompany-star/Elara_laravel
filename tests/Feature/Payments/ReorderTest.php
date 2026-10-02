<?php
// tests/Feature/Orders/ReorderTest.php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'price'      => 100,
            'sale_price' => null,
            'stock'      => 10,
            'status'     => 'active',
        ], $attributes));
    }

    private function orderItem(Order $order, Product $product, int $quantity, float $price = 40, string $status = 'delivered'): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'seller_id'  => $product->seller_id,
            'quantity'   => $quantity,
            'price'      => $price,
            'status'     => $status,
        ]);
    }

    private function reorder(User $user, Order $order, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/orders/{$order->id}/reorder", $data);
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_guest_gets_401(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/orders/{$order->id}/reorder")->assertUnauthorized();
    }

    public function test_user_cannot_reorder_someone_elses_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $this->orderItem($order, $this->product(), 2);

        $this->reorder($other, $order)->assertForbidden();

        $this->assertSame(0, $other->cart()->first()?->items()->count() ?? 0);
    }

    public function test_unknown_order_gets_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders/999999/reorder')->assertNotFound();
    }

    public function test_a_guest_order_can_be_reordered_only_with_its_number_and_email(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->guest()->create();
        $this->orderItem($order, $this->product(), 2);

        $this->reorder($user, $order)->assertStatus(422);

        $this->reorder($user, $order, [
            'order_number' => $order->order_number,
            'guest_email'  => 'wrong@example.com',
        ])->assertForbidden();

        $this->reorder($user, $order, [
            'order_number' => $order->order_number,
            'guest_email'  => $order->guest_email,
        ])->assertOk();

        $this->assertSame(1, $user->cart()->first()->items()->count());
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    public function test_items_are_added_to_a_new_cart_with_the_same_quantities(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $first = $this->product();
        $second = $this->product();
        $this->orderItem($order, $first, 3);
        $this->orderItem($order, $second, 1);

        $this->reorder($user, $order)
            ->assertOk()
            ->assertJsonPath('message', 'Items added to your cart from this order.')
            ->assertJsonCount(0, 'skipped');

        $cart = $user->cart()->first();

        $this->assertSame(3, $cart->items()->where('product_id', $first->id)->value('quantity'));
        $this->assertSame(1, $cart->items()->where('product_id', $second->id)->value('quantity'));
    }

    public function test_cart_price_is_the_current_price_not_the_old_order_price(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $product = $this->product(['price' => 55]);
        $this->orderItem($order, $product, 1, price: 40);   // bought at 40, costs 55 now

        $this->reorder($user, $order)->assertOk();

        $item = $user->cart()->first()->items()->firstOrFail();

        $this->assertEquals(55, (float) $item->price_at_add);
    }

    public function test_reordering_does_not_touch_stock_or_create_an_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $product = $this->product(['stock' => 10]);
        $this->orderItem($order, $product, 3);

        $this->reorder($user, $order)->assertOk();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, Order::count());
    }

    public function test_cancelled_orders_can_be_reordered_too(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->cancelled()->create(['user_id' => $user->id]);
        $this->orderItem($order, $this->product(), 2, status: 'cancelled');

        $this->reorder($user, $order)->assertOk();

        $this->assertSame(1, $user->cart()->first()->items()->count());
    }

    // ------------------------------------------------------------------
    // Merging with the existing cart
    // ------------------------------------------------------------------

    public function test_quantities_are_merged_into_an_existing_cart_item(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 10]);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price_at_add' => 100]);

        $order = Order::factory()->create(['user_id' => $user->id]);
        $this->orderItem($order, $product, 3);

        $this->reorder($user, $order)->assertOk()->assertJsonCount(0, 'skipped');

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(5, $cart->items()->first()->quantity);
    }

    public function test_an_item_that_would_exceed_stock_is_skipped_and_the_cart_is_left_alone(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['stock' => 4]);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price_at_add' => 100]);

        $order = Order::factory()->create(['user_id' => $user->id]);
        $this->orderItem($order, $product, 3);   // 2 + 3 = 5 > 4

        $this->reorder($user, $order)
            ->assertOk()
            ->assertJsonPath('skipped.0.product_id', $product->id)
            ->assertJsonPath('skipped.0.reason', 'out_of_stock');

        $this->assertSame(2, $cart->items()->first()->quantity);
    }

    // ------------------------------------------------------------------
    // Skipped items
    // ------------------------------------------------------------------

    public function test_unavailable_products_are_skipped_but_the_rest_is_added(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $available = $this->product();
        $inactive = $this->product(['status' => 'pending']);
        $this->orderItem($order, $available, 1);
        $this->orderItem($order, $inactive, 1);

        $this->reorder($user, $order)
            ->assertOk()
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.product_id', $inactive->id)
            ->assertJsonPath('skipped.0.reason', 'unavailable');

        $cart = $user->cart()->first();

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($available->id, $cart->items()->first()->product_id);
    }

    public function test_when_nothing_can_be_added_the_cart_stays_empty_and_everything_is_reported(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $outOfStock = $this->product(['stock' => 0]);
        $inactive = $this->product(['status' => 'pending']);
        $this->orderItem($order, $outOfStock, 1);
        $this->orderItem($order, $inactive, 1);

        $this->reorder($user, $order)->assertOk()->assertJsonCount(2, 'skipped');

        $this->assertSame(0, $user->cart()->first()->items()->count());
    }
}
