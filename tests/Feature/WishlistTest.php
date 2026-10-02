<?php
// tests/Feature/User/WishlistTest.php

namespace Tests\Feature\User;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_guest_cannot_use_the_wishlist(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/wishlist')->assertUnauthorized();
        $this->postJson('/api/wishlist', ['product_id' => $product->id])->assertUnauthorized();
        $this->deleteJson("/api/wishlist/{$product->id}")->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // POST /api/wishlist
    // ------------------------------------------------------------------

    public function test_user_can_add_an_active_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertCreated();

        $this->assertDatabaseHas('wishlists', ['user_id' => $user->id, 'product_id' => $product->id]);
    }

    public function test_adding_the_same_product_twice_does_not_duplicate(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertCreated();

        // التاني بيرجع 200 (مش 201) ومفيش صف جديد
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertOk();

        $this->assertSame(1, Wishlist::where('user_id', $user->id)->count());
    }

    public function test_cannot_add_a_non_active_product(): void
    {
        $user = User::factory()->create();

        foreach (['pending', 'hidden', 'rejected'] as $status) {
            $product = Product::factory()->create(['status' => $status]);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/wishlist', ['product_id' => $product->id])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('wishlists', 0);
    }

    public function test_cannot_add_a_soft_deleted_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $product->delete();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertNotFound();

        $this->assertDatabaseCount('wishlists', 0);
    }

    public function test_add_validates_the_product_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wishlist', ['product_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    // ------------------------------------------------------------------
    // GET /api/wishlist
    // ------------------------------------------------------------------

    public function test_index_returns_only_my_items_with_their_products(): void
    {
        $me    = User::factory()->create();
        $other = User::factory()->create();

        Wishlist::factory()->count(3)->create(['user_id' => $me->id]);
        Wishlist::factory()->count(2)->create(['user_id' => $other->id]);

        $response = $this->actingAs($me, 'sanctum')->getJson('/api/wishlist')->assertOk();

        $response->assertJsonPath('total', 3)->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertSame($me->id, $row['user_id']);
            $this->assertNotNull($row['product']);
        }
    }

    // ------------------------------------------------------------------
    // DELETE /api/wishlist/{product}
    // ------------------------------------------------------------------

    public function test_user_can_remove_a_product_from_the_wishlist(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/wishlist/{$product->id}")
            ->assertOk();

        $this->assertDatabaseMissing('wishlists', ['user_id' => $user->id, 'product_id' => $product->id]);
    }

    public function test_removing_a_product_that_is_not_in_the_wishlist_returns_404(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/wishlist/{$product->id}")
            ->assertNotFound();
    }

    public function test_removing_does_not_touch_another_users_wishlist(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $product  = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $owner->id, 'product_id' => $product->id]);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/wishlist/{$product->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('wishlists', ['user_id' => $owner->id, 'product_id' => $product->id]);
    }

    // ------------------------------------------------------------------
    // 🐞 تيستين المفروض يفشلوا دلوقتي: منتج اتحذف soft delete وهو في الـ wishlist
    // ------------------------------------------------------------------

    /**
     * باج 1: الـ route model binding بيدوّر على المنتج من غير المحذوفين،
     * فاليوزر مش بيقدر يشيل منتج محذوف من الـ wishlist بتاعته (404 قبل ما نوصل للـ controller).
     */
    public function test_a_soft_deleted_product_can_still_be_removed_from_the_wishlist(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

        $product->delete(); // soft delete: صف الـ wishlist فاضل موجود

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/wishlist/{$product->id}")
            ->assertOk();

        $this->assertDatabaseMissing('wishlists', ['user_id' => $user->id, 'product_id' => $product->id]);
    }

    /**
     * باج 2: الـ index بيرجّع الصف حتى لو المنتج اتحذف، فالفرونت بيستلم عنصر بـ product = null.
     */
    public function test_index_hides_entries_whose_product_was_deleted(): void
    {
        $user = User::factory()->create();
        $kept = Product::factory()->create();
        $gone = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $kept->id]);
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $gone->id]);

        $gone->delete();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/wishlist')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($kept->id, $response->json('data.0.product_id'));
    }
}
