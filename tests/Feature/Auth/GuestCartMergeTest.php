<?php
// tests/Feature/Auth/GuestCartMergeTest.php

namespace Tests\Feature\Auth;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers AuthController::mergeGuestCart() through POST /api/auth/login and /api/auth/register.
 * Each test sends at most 6 requests to /api/auth/* (shared throttle bucket).
 */
class GuestCartMergeTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'guest-sess-1';

    private function loginUser(string $email = 'merge@example.com'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function login(string $email = 'merge@example.com', ?string $session = self::SESSION)
    {
        $headers = $session ? ['X-Session-Id' => $session] : [];

        return $this->postJson('/api/auth/login', [
            'login'    => $email,
            'password' => 'password', // UserFactory default
        ], $headers);
    }

    /** Creates a guest cart and its items: [[Product, quantity, price_at_add], ...] */
    private function guestCart(array $items, string $session = self::SESSION): Cart
    {
        $cart = Cart::factory()->guest($session)->create();

        foreach ($items as [$product, $qty, $price]) {
            CartItem::factory()->create([
                'cart_id'      => $cart->id,
                'product_id'   => $product->id,
                'quantity'     => $qty,
                'price_at_add' => $price,
            ]);
        }

        return $cart;
    }

    private function userCart(User $user): ?Cart
    {
        return Cart::where('user_id', $user->id)->first();
    }

    // ------------------------------------------------------------------
    // Login merge
    // ------------------------------------------------------------------

    public function test_login_moves_guest_cart_items_into_the_users_cart(): void
    {
        $user = $this->loginUser();
        $a = Product::factory()->create(['stock' => 20]);
        $b = Product::factory()->create(['stock' => 20]);
        $guest = $this->guestCart([[$a, 2, 150], [$b, 3, 80]]);

        $this->login()->assertOk();

        $cart = $this->userCart($user);
        $this->assertNotNull($cart);
        $this->assertSame(
            [$a->id => 2, $b->id => 3],
            $cart->items()->pluck('quantity', 'product_id')->all()
        );
        $this->assertEquals(150, $cart->items()->where('product_id', $a->id)->value('price_at_add'));
        $this->assertEquals(80, $cart->items()->where('product_id', $b->id)->value('price_at_add'));

        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_same_product_in_both_carts_sums_quantities_and_keeps_the_users_price(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 20]);

        $userCart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $userCart->id, 'product_id' => $product->id, 'quantity' => 2, 'price_at_add' => 100,
        ]);
        $this->guestCart([[$product, 3, 999]]);

        $this->login()->assertOk();

        $item = $userCart->items()->where('product_id', $product->id)->first();
        $this->assertSame(5, $item->quantity);
        $this->assertEquals(100, $item->price_at_add);
        $this->assertSame(1, $userCart->items()->count());
    }

    public function test_existing_items_in_the_users_cart_are_kept(): void
    {
        $user  = $this->loginUser();
        $kept  = Product::factory()->create(['stock' => 20]);
        $added = Product::factory()->create(['stock' => 20]);

        $userCart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $userCart->id, 'product_id' => $kept->id, 'quantity' => 4, 'price_at_add' => 50,
        ]);
        $this->guestCart([[$added, 1, 70]]);

        $this->login()->assertOk();

        $this->assertSame(
            [$kept->id => 4, $added->id => 1],
            $userCart->items()->pluck('quantity', 'product_id')->all()
        );
    }

    public function test_summed_quantity_is_capped_at_available_stock(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 4]);

        $userCart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $userCart->id, 'product_id' => $product->id, 'quantity' => 3, 'price_at_add' => 100,
        ]);
        $this->guestCart([[$product, 3, 100]]);

        $this->login()->assertOk();

        $this->assertSame(4, $userCart->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_new_item_quantity_is_capped_at_available_stock(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 4]);
        $this->guestCart([[$product, 10, 100]]);

        $this->login()->assertOk();

        $this->assertSame(4, $this->userCart($user)->items()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_unavailable_products_are_skipped_during_the_merge(): void
    {
        $user = $this->loginUser();

        $good       = Product::factory()->create(['stock' => 10]);
        $hidden     = Product::factory()->create(['stock' => 10, 'status' => 'hidden']);
        $pending    = Product::factory()->pending()->create(['stock' => 10]);
        $rejected   = Product::factory()->create(['stock' => 10, 'status' => 'rejected']);
        $outOfStock = Product::factory()->outOfStock()->create();
        $deleted    = Product::factory()->create(['stock' => 10]);

        $this->guestCart([
            [$good, 1, 100], [$hidden, 1, 100], [$pending, 1, 100],
            [$rejected, 1, 100], [$outOfStock, 1, 100], [$deleted, 1, 100],
        ]);
        $deleted->delete();

        $this->login()->assertOk();

        $this->assertSame(
            [$good->id],
            $this->userCart($user)->items()->pluck('product_id')->all()
        );
    }

    public function test_guest_cart_is_deleted_after_a_successful_merge(): void
    {
        $this->loginUser();
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        $this->login()->assertOk();

        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $guest->id]);
    }

    public function test_login_without_a_session_header_leaves_the_guest_cart_alone(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        $this->login('merge@example.com', null)->assertOk();

        $this->assertDatabaseHas('carts', ['id' => $guest->id, 'session_id' => self::SESSION]);
        $this->assertNull($this->userCart($user));
    }

    public function test_unknown_session_id_logs_in_normally_and_creates_no_cart(): void
    {
        $user = $this->loginUser();

        $this->login('merge@example.com', 'nobody-has-this-session')
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $this->assertNull($this->userCart($user));
    }

    public function test_another_guests_cart_is_not_touched(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 10]);
        $mine    = $this->guestCart([[$product, 1, 100]], 'my-session');
        $theirs  = $this->guestCart([[$product, 2, 100]], 'their-session');

        $this->login('merge@example.com', 'my-session')->assertOk();

        $this->assertDatabaseMissing('carts', ['id' => $mine->id]);
        $this->assertDatabaseHas('carts', ['id' => $theirs->id]);
        $this->assertSame(1, $this->userCart($user)->items()->value('quantity'));
    }

    public function test_a_cart_that_already_belongs_to_a_user_is_never_merged_by_session_id(): void
    {
        $user    = $this->loginUser();
        $victim  = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        // A logged-in user's cart that happens to carry a session id.
        $victimCart = Cart::factory()->create(['user_id' => $victim->id, 'session_id' => self::SESSION]);
        CartItem::factory()->create([
            'cart_id' => $victimCart->id, 'product_id' => $product->id, 'quantity' => 2, 'price_at_add' => 100,
        ]);

        $this->login()->assertOk();

        $this->assertDatabaseHas('carts', ['id' => $victimCart->id, 'user_id' => $victim->id]);
        $this->assertSame(1, $victimCart->items()->count());
        $this->assertNull($this->userCart($user));
    }

    public function test_failed_login_does_not_merge_the_guest_cart(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        $this->postJson('/api/auth/login', [
            'login' => 'merge@example.com', 'password' => 'wrong-password',
        ], ['X-Session-Id' => self::SESSION])->assertStatus(422);

        $this->assertDatabaseHas('carts', ['id' => $guest->id]);
        $this->assertNull($this->userCart($user));
    }

    public function test_suspended_user_login_does_not_merge_the_guest_cart(): void
    {
        $user    = User::factory()->create(['email' => 'merge@example.com', 'is_active' => false]);
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        $this->login()->assertStatus(422);

        $this->assertDatabaseHas('carts', ['id' => $guest->id]);
        $this->assertNull($this->userCart($user));
    }

    public function test_a_failure_during_the_merge_never_blocks_login(): void
    {
        $user    = $this->loginUser();
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        // Make creating the user's cart blow up inside the merge transaction.
        Cart::creating(function () {
            throw new \RuntimeException('boom');
        });

        $this->login()
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        // Transaction rolled back: guest cart is intact, no user cart was created.
        $this->assertDatabaseHas('carts', ['id' => $guest->id]);
        $this->assertSame(0, Cart::where('user_id', $user->id)->count());
        $this->assertSame(1, $user->tokens()->count());
    }

    // ------------------------------------------------------------------
    // Register merge
    // ------------------------------------------------------------------

    private function register(?string $session = self::SESSION)
    {
        $headers = $session ? ['X-Session-Id' => $session] : [];

        return $this->postJson('/api/auth/register', [
            'name'                  => 'New User',
            'email'                 => 'new@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ], $headers);
    }

    public function test_register_merges_the_guest_cart_into_the_new_users_cart(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 2, 120]]);

        $response = $this->register()->assertCreated();

        $user = User::where('email', 'new@example.com')->first();
        $cart = $this->userCart($user);

        $this->assertNotNull($cart);
        $this->assertSame([$product->id => 2], $cart->items()->pluck('quantity', 'product_id')->all());
        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
        $this->assertArrayHasKey('token', $response->json());
    }

    public function test_register_without_a_session_header_keeps_the_guest_cart(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 2, 120]]);

        $this->register(null)->assertCreated();

        $this->assertDatabaseHas('carts', ['id' => $guest->id]);
        $this->assertNull($this->userCart(User::where('email', 'new@example.com')->first()));
    }

    public function test_a_failure_during_the_merge_never_blocks_registration(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart([[$product, 1, 100]]);

        Cart::creating(function () {
            throw new \RuntimeException('boom');
        });

        $this->register()
            ->assertCreated()
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertDatabaseHas('carts', ['id' => $guest->id]);
    }
}