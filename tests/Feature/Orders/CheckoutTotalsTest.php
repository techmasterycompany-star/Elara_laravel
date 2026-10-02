<?php
// tests/Feature/Orders/CheckoutTotalsTest.php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Business rules under test (from OrderController / CartController):
 *   shipping fee            = 100
 *   subtotal >= 1000        -> tier discount 100
 *   subtotal >= 2000        -> tier discount 250
 *   coupon vs tier          -> the bigger one wins; on a tie the coupon wins
 *   total                   = subtotal - discount + shipping
 */
class CheckoutTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function payload(string $method = 'cod'): array
    {
        return [
            'shipping_name'        => 'Test Customer',
            'shipping_phone'       => '01000000000',
            'shipping_street'      => '123 Test St',
            'shipping_city'        => 'Cairo',
            'shipping_governorate' => 'Cairo',
            'payment_method'       => $method,
        ];
    }

    /**
     * Builds a cart for $user. $lines is a list of [price, quantity] or
     * [price, quantity, sale_price].
     */
    private function cart(User $user, array $lines, ?Coupon $coupon = null): Cart
    {
        $cart = $user->cart()->create(['coupon_id' => $coupon?->id]);

        foreach ($lines as $line) {
            [$price, $quantity] = $line;

            $product = Product::factory()->create([
                'price'      => $price,
                'sale_price' => $line[2] ?? null,
                'stock'      => 50,
                'status'     => 'active',
            ]);

            $cart->items()->create([
                'product_id'   => $product->id,
                'quantity'     => $quantity,
                'price_at_add' => $price,
            ]);
        }

        return $cart;
    }

    private function place(User $user, string $method = 'cod')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/orders', $this->payload($method));
    }

    private function placeAndGetOrder(User $user, string $method = 'cod'): Order
    {
        $response = $this->place($user, $method)->assertCreated();

        return Order::findOrFail($response->json('order.id'));
    }

    private function assertTotals(Order $order, float $subtotal, float $discount, float $total): void
    {
        $this->assertEquals($subtotal, (float) $order->subtotal, 'subtotal');
        $this->assertEquals($discount, (float) $order->discount, 'discount');
        $this->assertEquals(100, (float) $order->shipping_fee, 'shipping fee');
        $this->assertEquals($total, (float) $order->total, 'total');
    }

    // ------------------------------------------------------------------
    // Basic totals
    // ------------------------------------------------------------------

    public function test_total_is_subtotal_plus_shipping_when_there_is_no_discount(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[100, 2]]);

        $this->assertTotals($this->placeAndGetOrder($user), 200, 0, 300);
    }

    public function test_sale_price_is_used_when_a_product_has_one(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[100, 2, 80]]);

        $this->assertTotals($this->placeAndGetOrder($user), 160, 0, 260);
    }

    public function test_several_lines_are_added_up(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[100, 2], [50, 3], [19.99, 1]]);

        $this->assertTotals($this->placeAndGetOrder($user), 369.99, 0, 469.99);
    }

    public function test_order_items_keep_the_price_and_the_seller_of_the_product(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[100, 2, 80]]);

        $order = $this->placeAndGetOrder($user);
        $item = $order->items()->firstOrFail();

        $this->assertEquals(80, (float) $item->price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('pending', $item->status);
        $this->assertSame($item->product->seller_id, $item->seller_id);
    }

    public function test_order_number_is_generated_from_the_order_id(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[100, 1]]);

        $order = $this->placeAndGetOrder($user);

        $this->assertSame(
            'GT-' . now()->format('Y') . '-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            $order->order_number
        );
    }

    // ------------------------------------------------------------------
    // Tier discounts
    // ------------------------------------------------------------------

    public function test_no_tier_discount_just_below_1000(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[999.99, 1]]);

        $this->assertTotals($this->placeAndGetOrder($user), 999.99, 0, 1099.99);
    }

    public function test_tier_discount_of_100_at_exactly_1000(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[500, 2]]);

        $this->assertTotals($this->placeAndGetOrder($user), 1000, 100, 1000);
    }

    public function test_tier_discount_of_250_at_exactly_2000(): void
    {
        $user = User::factory()->create();
        $this->cart($user, [[1000, 2]]);

        $this->assertTotals($this->placeAndGetOrder($user), 2000, 250, 1850);
    }

    // ------------------------------------------------------------------
    // Coupons
    // ------------------------------------------------------------------

    public function test_percent_coupon_is_applied_and_counted(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->percent(10)->create();
        $cart = $this->cart($user, [[250, 2]], $coupon);

        $order = $this->placeAndGetOrder($user);

        $this->assertTotals($order, 500, 50, 550);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertNull($cart->fresh()->coupon_id);
    }

    public function test_fixed_coupon_is_applied(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 50]);
        $this->cart($user, [[100, 2]], $coupon);

        $this->assertTotals($this->placeAndGetOrder($user), 200, 50, 250);
    }

    public function test_fixed_coupon_never_discounts_more_than_the_subtotal(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 500]);
        $this->cart($user, [[100, 2]], $coupon);

        // Only the shipping fee is left to pay.
        $this->assertTotals($this->placeAndGetOrder($user), 200, 200, 100);
    }

    public function test_a_bigger_tier_discount_beats_a_smaller_coupon_and_the_coupon_is_not_used(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 50]);
        $this->cart($user, [[500, 2]], $coupon);   // subtotal 1000 -> tier 100

        $order = $this->placeAndGetOrder($user);

        $this->assertTotals($order, 1000, 100, 1000);
        $this->assertNull($order->coupon_id);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_bigger_coupon_beats_the_tier_discount(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->percent(20)->create();
        $this->cart($user, [[500, 2]], $coupon);   // coupon 200 vs tier 100

        $order = $this->placeAndGetOrder($user);

        $this->assertTotals($order, 1000, 200, 900);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_on_a_tie_the_coupon_wins(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 100]);
        $this->cart($user, [[500, 2]], $coupon);   // coupon 100 == tier 100

        $order = $this->placeAndGetOrder($user);

        $this->assertTotals($order, 1000, 100, 1000);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_expired_and_used_up_coupons_are_ignored_but_the_order_still_goes_through(): void
    {
        foreach ([Coupon::factory()->expired(), Coupon::factory()->maxedOut()] as $factory) {
            $user = User::factory()->create();
            $coupon = $factory->create();
            $this->cart($user, [[100, 2]], $coupon);

            $order = $this->placeAndGetOrder($user);

            $this->assertTotals($order, 200, 0, 300);
            $this->assertNull($order->coupon_id);
        }
    }

    public function test_a_coupon_on_its_last_allowed_use_cannot_be_used_twice(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 1, 'used_count' => 0]);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->cart($first, [[100, 2]], $coupon);
        $this->cart($second, [[100, 2]], $coupon);   // coupon was attached earlier, before the limit was hit

        $this->assertTotals($this->placeAndGetOrder($first), 200, 50, 250);
        $this->assertTotals($this->placeAndGetOrder($second), 200, 0, 300);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    // ------------------------------------------------------------------
    // Cart after checkout / consistency with the cart summary
    // ------------------------------------------------------------------

    public function test_cart_is_emptied_after_checkout(): void
    {
        $user = User::factory()->create();
        $cart = $this->cart($user, [[100, 2]]);

        $this->placeAndGetOrder($user);

        $this->assertSame(0, $cart->items()->count());
    }

    public function test_the_order_total_matches_what_the_cart_summary_showed(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->percent(20)->create();
        $this->cart($user, [[500, 2], [30, 1]], $coupon);

        $summary = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $order = $this->placeAndGetOrder($user);

        $this->assertEquals((float) $summary->json('subtotal'), (float) $order->subtotal);
        $this->assertEquals((float) $summary->json('discount'), (float) $order->discount);
        $this->assertEquals((float) $summary->json('shipping_fee'), (float) $order->shipping_fee);
        $this->assertEquals((float) $summary->json('total'), (float) $order->total);
    }

    public function test_checkout_with_an_empty_or_missing_cart_gets_422(): void
    {
        $withoutCart = User::factory()->create();
        $withEmptyCart = User::factory()->create();
        $withEmptyCart->cart()->create();

        $this->place($withoutCart)->assertStatus(422);
        $this->place($withEmptyCart)->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------------
    // Wallet at checkout
    // ------------------------------------------------------------------

    public function test_wallet_checkout_charges_the_wallet_and_marks_the_order_paid(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $this->cart($user, [[100, 2]]);

        $response = $this->place($user, 'wallet')
            ->assertCreated()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('order.status', 'paid');

        $this->assertEquals(700, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('payments', [
            'order_id' => $response->json('order.id'),
            'gateway'  => 'wallet',
            'status'   => 'paid',
        ]);
    }

    public function test_wallet_checkout_with_too_little_balance_creates_nothing(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        $cart = $this->cart($user, [[100, 2]]);
        $productId = $cart->items()->first()->product_id;

        $this->place($user, 'wallet')->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(50, Product::find($productId)->stock);
        $this->assertSame(1, $cart->items()->count());
        $this->assertEquals(100, (float) $user->fresh()->wallet_balance);
    }

    public function test_guests_cannot_check_out_with_the_wallet(): void
    {
        $product = Product::factory()->create(['price' => 100, 'sale_price' => null, 'stock' => 10, 'status' => 'active']);
        $cart = Cart::create(['session_id' => 'wallet-guest-1']);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price_at_add' => 100]);

        $this->withHeaders(['X-Session-Id' => 'wallet-guest-1'])
            ->postJson('/api/orders', $this->payload('wallet') + ['guest_email' => 'guest@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Wallet payment requires a logged-in account.');

        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------------
    // Hypothesis test: coupon that expires today
    // ------------------------------------------------------------------

    public function test_a_coupon_that_expires_today_can_still_be_applied_today(): void
    {
        // expires_at is cast to a date (midnight), and isExpired() uses isPast(),
        // so a coupon whose last day is today may already count as expired.
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create(['expires_at' => today()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/coupon', ['code' => $coupon->code])
            ->assertOk();
    }
}
