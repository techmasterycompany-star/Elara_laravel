<?php
// tests/Feature/Auth/GoogleCartMergeTest.php

namespace Tests\Feature\Auth;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleCartMergeTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_ID = 'google-123';
    private const EMAIL     = 'gina@example.com';
    private const SESSION   = 'guest-sess-g1';

    private function fakeGoogle(): void
    {
        $googleUser = (new SocialiteUser)->map(['id' => self::GOOGLE_ID, 'name' => 'Gina', 'email' => self::EMAIL]);

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function googleCallback(?string $session = self::SESSION)
    {
        $headers = $session ? ['X-Session-Id' => $session] : [];

        return $this->getJson('/api/auth/google/callback?code=fake-code', $headers);
    }

    private function guestCart(Product $product, int $qty = 2, string $session = self::SESSION): Cart
    {
        $cart = Cart::factory()->guest($session)->create();

        CartItem::factory()->create([
            'cart_id'      => $cart->id,
            'product_id'   => $product->id,
            'quantity'     => $qty,
            'price_at_add' => 100,
        ]);

        return $cart;
    }

    public function test_first_google_login_moves_the_guest_cart_into_the_new_users_cart(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart($product, 2);
        $this->fakeGoogle();

        $this->googleCallback()->assertOk();

        $user = User::where('email', self::EMAIL)->first();
        $cart = Cart::where('user_id', $user->id)->first();

        $this->assertNotNull($cart);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(2, (int) $cart->items()->first()->quantity);
        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
    }

    public function test_linking_an_existing_account_merges_into_its_existing_cart(): void
    {
        $user  = User::factory()->create(['email' => self::EMAIL]);
        $other = Product::factory()->create(['stock' => 10]);
        $same  = Product::factory()->create(['stock' => 10]);

        $userCart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create(['cart_id' => $userCart->id, 'product_id' => $same->id, 'quantity' => 1, 'price_at_add' => 100]);

        $guest = $this->guestCart($same, 2);
        CartItem::factory()->create(['cart_id' => $guest->id, 'product_id' => $other->id, 'quantity' => 1, 'price_at_add' => 50]);

        $this->fakeGoogle();
        $this->googleCallback()->assertOk();

        $this->assertSame(2, $userCart->items()->count());
        $this->assertSame(3, (int) $userCart->items()->where('product_id', $same->id)->first()->quantity);
        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
    }

    public function test_google_login_without_a_session_header_leaves_the_guest_cart_alone(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart($product);
        $this->fakeGoogle();

        $this->googleCallback(null)->assertOk();

        $this->assertDatabaseHas('carts', ['id' => $guest->id, 'user_id' => null]);
    }

    public function test_another_guests_cart_is_not_touched(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $mine    = $this->guestCart($product, 1, self::SESSION);
        $theirs  = $this->guestCart($product, 5, 'someone-else');
        $this->fakeGoogle();

        $this->googleCallback()->assertOk();

        $this->assertDatabaseMissing('carts', ['id' => $mine->id]);
        $this->assertDatabaseHas('carts', ['id' => $theirs->id, 'user_id' => null]);
    }

    public function test_a_suspended_account_does_not_get_the_guest_cart(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'is_active' => false]);
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart($product);
        $this->fakeGoogle();

        $this->googleCallback()->assertStatus(422);

        $this->assertDatabaseHas('carts', ['id' => $guest->id, 'user_id' => null]);
    }

    public function test_a_failure_during_the_merge_never_blocks_google_login(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $guest   = $this->guestCart($product);
        $this->fakeGoogle();

        Cart::creating(function () {
            throw new \RuntimeException('boom');
        });

        $this->googleCallback()
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('carts', ['id' => $guest->id, 'user_id' => null]);
    }
}
