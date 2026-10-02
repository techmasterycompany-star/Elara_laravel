<?php
// tests/Feature/Cart/CartTest.php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function addItem(Cart $cart, Product $product, int $qty = 1): CartItem
    {
        return CartItem::factory()->create([
            'cart_id'      => $cart->id,
            'product_id'   => $product->id,
            'quantity'     => $qty,
            'price_at_add' => $product->currentPrice(),
        ]);
    }

    private function guestHeaders(string $session = 'guest-session-1'): array
    {
        return ['X-Session-Id' => $session];
    }

    // ------------------------------------------------------------------
    // GET /api/cart
    // ------------------------------------------------------------------

    public function test_guest_without_a_session_id_is_rejected(): void
    {
        $this->getJson('/api/cart')->assertStatus(422);
    }

    public function test_guest_with_no_cart_yet_gets_an_empty_cart(): void
    {
        $response = $this->getJson('/api/cart', $this->guestHeaders())->assertOk();

        $this->assertSame([], $response->json('items'));
        $this->assertEquals(0, $response->json('subtotal'));
    }

    public function test_cart_flags_unavailable_products_and_keeps_the_price_at_add(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);

        $hidden = Product::factory()->create(['price' => 300, 'status' => 'hidden']);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id, 'product_id' => $hidden->id,
            'quantity' => 1, 'price_at_add' => 120,
        ]);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->json('items.0');

        $this->assertFalse($row['available']);
        $this->assertEquals(120, $row['price']);
    }

    public function test_cart_flags_items_whose_quantity_exceeds_stock(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $this->addItem($cart, Product::factory()->create(['stock' => 2]), 5);

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/cart')->json('items.0');

        $this->assertTrue($row['available']);
        $this->assertFalse($row['in_stock']);
    }

    // ------------------------------------------------------------------
    // POST /api/cart/items
    // ------------------------------------------------------------------

    public function test_logged_in_user_can_add_an_item(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
    }

    public function test_guest_can_add_an_item_with_a_session_id_and_price_is_snapshotted(): void
    {
$product = Product::factory()->create(['price' => 500, 'sale_price' => 400, 'stock' => 10]);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders())
            ->assertCreated();

        $this->assertDatabaseHas('carts', ['session_id' => 'guest-session-1', 'user_id' => null]);
        $this->assertEquals(400, CartItem::first()->price_at_add);
    }

    public function test_guest_cannot_add_without_a_session_id(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_adding_the_same_product_twice_increases_quantity_without_duplicating_the_row(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3]);

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(5, CartItem::first()->quantity);
    }

    public function test_cannot_add_more_than_available_stock_even_cumulatively(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['stock' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(422);

        $this->assertSame(2, CartItem::first()->quantity);
    }

    public function test_cannot_add_a_non_active_product(): void
    {
        $user = User::factory()->create();

        foreach (['pending', 'hidden', 'rejected'] as $status) {
            $product = Product::factory()->create(['status' => $status]);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_add_validates_product_and_quantity(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => 999999, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        foreach ([0, -1, 'abc'] as $bad) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('quantity');
        }
    }

    public function test_cannot_add_a_soft_deleted_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $product->delete();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

        // المهم إنه مايتضافش ومايبقاش 500 (حاليًا بيرجع 404 من findOrFail)
        $this->assertContains($response->status(), [404, 422]);
        $this->assertDatabaseCount('cart_items', 0);
    }

    // ------------------------------------------------------------------
    // PUT /api/cart/items/{item}
    // ------------------------------------------------------------------

    public function test_owner_can_update_quantity(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = $this->addItem($cart, Product::factory()->create(['stock' => 10]), 1);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 4])
            ->assertOk();

        $this->assertSame(4, $item->fresh()->quantity);
    }

    public function test_update_rejects_quantity_above_stock_or_below_one(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = $this->addItem($cart, Product::factory()->create(['stock' => 3]), 1);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 4])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_update_is_rejected_when_the_product_is_no_longer_active(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = $this->addItem($cart, Product::factory()->create(['status' => 'hidden', 'stock' => 10]), 1);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])
            ->assertStatus(422);
    }

    public function test_another_user_cannot_update_or_delete_my_cart_item(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $owner->id]);
        $item = $this->addItem($cart, Product::factory()->create(['stock' => 10]), 1);

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/cart/items/{$item->id}", ['quantity' => 5])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/cart/items/{$item->id}")
            ->assertForbidden();

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_guest_can_modify_only_with_the_matching_session_id(): void
    {
        $cart = Cart::factory()->guest('sess-A')->create();
        $item = $this->addItem($cart, Product::factory()->create(['stock' => 10]), 1);

        $this->putJson("/api/cart/items/{$item->id}", ['quantity' => 3], ['X-Session-Id' => 'sess-B'])
            ->assertForbidden();

        $this->putJson("/api/cart/items/{$item->id}", ['quantity' => 3])
            ->assertForbidden();

        $this->putJson("/api/cart/items/{$item->id}", ['quantity' => 3], ['X-Session-Id' => 'sess-A'])
            ->assertOk();

        $this->assertSame(3, $item->fresh()->quantity);
    }

    public function test_guest_cannot_touch_a_logged_in_users_item(): void
    {
        $cart = Cart::factory()->create(); // مربوط بيوزر
        $item = $this->addItem($cart, Product::factory()->create(['stock' => 10]), 1);

        $this->deleteJson("/api/cart/items/{$item->id}", [], ['X-Session-Id' => 'anything'])
            ->assertForbidden();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    // ------------------------------------------------------------------
    // DELETE /api/cart/items/{item}
    // ------------------------------------------------------------------

    public function test_owner_can_remove_an_item(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = $this->addItem($cart, Product::factory()->create(), 1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/cart/items/{$item->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    // ------------------------------------------------------------------
    // GET /api/cart/summary
    // ------------------------------------------------------------------

    public function test_empty_cart_summary_cannot_checkout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertFalse($response->json('can_checkout'));
        $this->assertEquals(0, $response->json('shipping_fee'));
        $this->assertEquals(0, $response->json('total'));
    }

    public function test_summary_adds_flat_shipping_and_no_discount_below_the_first_tier(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $this->addItem($cart, Product::factory()->create(['price' => 300, 'stock' => 10]), 2); // 600

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertEquals(600, $r->json('subtotal'));
        $this->assertEquals(0, $r->json('discount'));
        $this->assertNull($r->json('discount_source'));
        $this->assertEquals(100, $r->json('shipping_fee'));
        $this->assertEquals(700, $r->json('total'));
        $this->assertTrue($r->json('can_checkout'));
    }

    public function test_summary_applies_the_tier_discounts(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = $this->addItem($cart, Product::factory()->create(['price' => 500, 'stock' => 20]), 2); // 1000

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary');
        $this->assertEquals(100, $r->json('discount'));
        $this->assertSame('tier', $r->json('discount_source'));
        $this->assertEquals(1000, $r->json('total')); // 1000 - 100 + 100

        $item->update(['quantity' => 4]); // 2000

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary');
        $this->assertEquals(250, $r->json('discount'));
        $this->assertEquals(1850, $r->json('total')); // 2000 - 250 + 100
    }

    public function test_summary_uses_the_coupon_when_it_beats_the_tier_discount(): void
    {
        $user   = User::factory()->create();
        $coupon = Coupon::factory()->percent(20)->create(); // 20% من 1000 = 200
        $cart   = Cart::factory()->create(['user_id' => $user->id, 'coupon_id' => $coupon->id]);
        $this->addItem($cart, Product::factory()->create(['price' => 500, 'stock' => 20]), 2);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary');

        $this->assertEquals(200, $r->json('discount'));
        $this->assertSame('coupon', $r->json('discount_source'));
        $this->assertEquals(900, $r->json('total'));
    }

    public function test_summary_keeps_the_tier_discount_when_the_coupon_is_smaller(): void
    {
        $user   = User::factory()->create();
        $coupon = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 50]);
        $cart   = Cart::factory()->create(['user_id' => $user->id, 'coupon_id' => $coupon->id]);
        $this->addItem($cart, Product::factory()->create(['price' => 500, 'stock' => 20]), 2);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary');

        $this->assertEquals(100, $r->json('discount'));
        $this->assertSame('tier', $r->json('discount_source'));
    }

    public function test_summary_drops_an_expired_coupon_and_reports_why(): void
    {
        $user   = User::factory()->create();
        $coupon = Coupon::factory()->expired()->create();
        $cart   = Cart::factory()->create(['user_id' => $user->id, 'coupon_id' => $coupon->id]);
        $this->addItem($cart, Product::factory()->create(['price' => 300, 'stock' => 10]), 1);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertSame('expired', $r->json('coupon_removed.reason'));
        $this->assertNull($cart->fresh()->coupon_id);
    }

    public function test_summary_drops_a_coupon_that_reached_its_usage_limit(): void
    {
        $user   = User::factory()->create();
        $coupon = Coupon::factory()->maxedOut()->create();
        $cart   = Cart::factory()->create(['user_id' => $user->id, 'coupon_id' => $coupon->id]);
        $this->addItem($cart, Product::factory()->create(['price' => 300, 'stock' => 10]), 1);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertSame('usage_limit_reached', $r->json('coupon_removed.reason'));
        $this->assertNull($cart->fresh()->coupon_id);
    }

    public function test_summary_excludes_problem_items_and_blocks_checkout(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);

        $good      = Product::factory()->create(['price' => 300, 'stock' => 10]);
        $soldOut   = Product::factory()->create(['price' => 100, 'stock' => 1]);
        $hidden    = Product::factory()->create(['price' => 100, 'stock' => 10, 'status' => 'hidden']);

        $this->addItem($cart, $good, 1);
        $oosItem    = $this->addItem($cart, $soldOut, 5);
        $hiddenItem = $this->addItem($cart, $hidden, 1);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertEquals(300, $r->json('subtotal'));
        $this->assertCount(1, $r->json('items'));
        $this->assertFalse($r->json('can_checkout'));

        $reasons = collect($r->json('issues'))->pluck('reason', 'cart_item_id');
        $this->assertSame('out_of_stock', $reasons[$oosItem->id]);
        $this->assertSame('unavailable', $reasons[$hiddenItem->id]);
    }

    public function test_summary_treats_a_soft_deleted_product_as_unavailable(): void
    {
        $user    = User::factory()->create();
        $cart    = Cart::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['stock' => 10]);
        $item    = $this->addItem($cart, $product, 1);

        $product->delete();

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/cart/summary')->assertOk();

        $this->assertSame('unavailable', $r->json('issues.0.reason'));
        $this->assertFalse($r->json('can_checkout'));
    }

    // ------------------------------------------------------------------
    // POST/DELETE /api/cart/coupon
    // ------------------------------------------------------------------

    public function test_a_valid_coupon_can_be_applied_and_removed(): void
    {
        $user   = User::factory()->create();
        $coupon = Coupon::factory()->create(['code' => 'WELCOME10']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/coupon', ['code' => 'WELCOME10'])
            ->assertOk();

        $this->assertSame($coupon->id, $user->cart()->first()->coupon_id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/cart/coupon')
            ->assertOk();

        $this->assertNull($user->cart()->first()->coupon_id);
    }

    public function test_guest_can_apply_a_coupon_with_a_session_id(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'GUEST5']);

        $this->postJson('/api/cart/coupon', ['code' => 'GUEST5'], $this->guestHeaders())->assertOk();

        $this->assertSame($coupon->id, Cart::where('session_id', 'guest-session-1')->first()->coupon_id);
    }

    public function test_unknown_expired_or_maxed_out_coupons_are_rejected(): void
    {
        $user = User::factory()->create();
        Coupon::factory()->expired()->create(['code' => 'OLD']);
        Coupon::factory()->maxedOut()->create(['code' => 'USEDUP']);

        foreach (['NOPE', 'OLD', 'USEDUP'] as $code) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/cart/coupon', ['code' => $code])
                ->assertStatus(422);
        }

        $this->assertNull($user->cart()->first()?->coupon_id);
    }

    public function test_applying_a_coupon_requires_a_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/coupon', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_removing_a_coupon_without_a_cart_is_harmless(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/cart/coupon')
            ->assertOk();
    }
}
