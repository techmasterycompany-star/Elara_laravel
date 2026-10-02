<?php
// tests/Feature/Catalog/ReviewTest.php

namespace Tests\Feature\Catalog;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /** يوزر اشترى المنتج وطلبه بالحالة المطلوبة (delivered افتراضيًا) */
    private function purchase(User $user, Product $product, string $status = 'delivered'): OrderItem
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        return OrderItem::factory()->create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'status'     => $status,
        ]);
    }

    // ------------------------------------------------------------------
    // GET /api/products/{product}/reviews
    // ------------------------------------------------------------------

    public function test_guest_can_list_reviews_with_average_and_total(): void
    {
        $product = Product::factory()->create();
        Review::factory()->create(['product_id' => $product->id, 'rating' => 5]);
        Review::factory()->create(['product_id' => $product->id, 'rating' => 4]);

        $response = $this->getJson("/api/products/{$product->id}/reviews")->assertOk();

        $this->assertEquals(4.5, $response->json('average_rating'));
        $this->assertSame(2, $response->json('total_reviews'));
        $this->assertCount(2, $response->json('reviews.data'));
    }

    public function test_a_product_with_no_reviews_has_zero_average(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}/reviews")->assertOk();

        $this->assertEquals(0, $response->json('average_rating'));
        $this->assertSame(0, $response->json('total_reviews'));
    }

    public function test_reviews_are_paginated_by_ten(): void
    {
        $product = Product::factory()->create();
        Review::factory()->count(12)->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/products/{$product->id}/reviews")->assertOk();

        $this->assertCount(10, $response->json('reviews.data'));
        $this->assertSame(12, $response->json('reviews.total'));
    }

    public function test_reviews_only_expose_safe_user_fields(): void
    {
        $product = Product::factory()->create();
        Review::factory()->create(['product_id' => $product->id]);

        $user = $this->getJson("/api/products/{$product->id}/reviews")->json('reviews.data.0.user');

        $this->assertEqualsCanonicalizing(['id', 'name', 'avatar'], array_keys($user));
        $this->assertArrayNotHasKey('email', $user);
    }

    public function test_reviews_can_be_sorted(): void
    {
        $product = Product::factory()->create();

        $old  = Review::factory()->create(['product_id' => $product->id, 'rating' => 3, 'created_at' => now()->subDays(3)]);
        $mid  = Review::factory()->create(['product_id' => $product->id, 'rating' => 5, 'created_at' => now()->subDays(2)]);
        $new  = Review::factory()->create(['product_id' => $product->id, 'rating' => 1, 'created_at' => now()->subDay()]);

        $ids = fn (string $sort) => $this->getJson("/api/products/{$product->id}/reviews?sort={$sort}")
            ->assertOk()
            ->json('reviews.data.*.id');

        $this->assertSame([$new->id, $mid->id, $old->id], $ids('newest'));
        $this->assertSame([$mid->id, $old->id, $new->id], $ids('highest'));
        $this->assertSame([$new->id, $old->id, $mid->id], $ids('lowest'));
    }

    public function test_an_unknown_sort_value_is_rejected(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/api/products/{$product->id}/reviews?sort=random")
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_listing_reviews_of_a_deleted_product_returns_404(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->getJson("/api/products/{$product->id}/reviews")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // POST /api/products/{product}/reviews
    // ------------------------------------------------------------------

    public function test_guest_cannot_post_a_review(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])->assertUnauthorized();
    }

    public function test_verified_purchaser_can_review(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 4, 'comment' => 'Great'])
            ->assertCreated()
            ->assertJsonPath('review.rating', 4);

        $this->assertDatabaseHas('reviews', [
            'product_id' => $product->id,
            'user_id'    => $user->id,
            'comment'    => 'Great',
        ]);
    }

    public function test_comment_is_optional(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertCreated();
    }

    public function test_user_who_never_bought_the_product_cannot_review(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_only_delivered_items_count_as_a_purchase(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        foreach (['pending', 'processing', 'shipped', 'cancelled'] as $status) {
            $this->purchase($user, $product, $status);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_someone_elses_delivered_purchase_does_not_qualify_me(): void
    {
        $buyer    = User::factory()->create();
        $stranger = User::factory()->create();
        $product  = Product::factory()->create();
        $this->purchase($buyer, $product);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_a_delivered_purchase_of_a_different_product_does_not_qualify(): void
    {
        $user         = User::factory()->create();
        $boughtOne    = Product::factory()->create();
        $reviewedOne  = Product::factory()->create();
        $this->purchase($user, $boughtOne);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$reviewedOne->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_a_guest_order_with_no_user_does_not_qualify_anyone(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();

        $guestOrder = Order::factory()->guest()->create();
        OrderItem::factory()->delivered()->create([
            'order_id' => $guestOrder->id, 'product_id' => $product->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_cannot_review_the_same_product_twice(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 1])
            ->assertStatus(409);

        $this->assertSame(1, Review::where('product_id', $product->id)->count());
    }

    public function test_review_validation(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);

        foreach ([null, 0, 6, 'abc', 3.5] as $badRating) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/products/{$product->id}/reviews", ['rating' => $badRating])
                ->assertStatus(422)
                ->assertJsonValidationErrors('rating');
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5, 'comment' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_cannot_review_a_deleted_product(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);
        $product->delete();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // PUT /api/reviews/{review}
    // ------------------------------------------------------------------

    public function test_owner_can_update_their_review(): void
    {
        $user   = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $user->id, 'rating' => 2, 'comment' => 'meh']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/reviews/{$review->id}", ['rating' => 5, 'comment' => 'much better'])
            ->assertOk();

        $this->assertSame(5, $review->fresh()->rating);
        $this->assertSame('much better', $review->fresh()->comment);
    }

    public function test_partial_update_keeps_the_other_field(): void
    {
        $user   = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $user->id, 'rating' => 3, 'comment' => 'keep me']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/reviews/{$review->id}", ['rating' => 4])
            ->assertOk();

        $this->assertSame(4, $review->fresh()->rating);
        $this->assertSame('keep me', $review->fresh()->comment);
    }

    public function test_update_rejects_an_invalid_rating(): void
    {
        $user   = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $user->id, 'rating' => 3]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/reviews/{$review->id}", ['rating' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');

        $this->assertSame(3, $review->fresh()->rating);
    }

    public function test_another_user_cannot_update_or_delete_my_review(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $review   = Review::factory()->create(['user_id' => $owner->id, 'rating' => 5, 'comment' => 'mine']);

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/reviews/{$review->id}", ['rating' => 1, 'comment' => 'hacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/reviews/{$review->id}")
            ->assertForbidden();

        $this->assertSame(5, $review->fresh()->rating);
        $this->assertSame('mine', $review->fresh()->comment);
    }

    public function test_guest_cannot_update_or_delete_reviews(): void
    {
        $review = Review::factory()->create();

        $this->putJson("/api/reviews/{$review->id}", ['rating' => 1])->assertUnauthorized();
        $this->deleteJson("/api/reviews/{$review->id}")->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // DELETE /api/reviews/{review}
    // ------------------------------------------------------------------

    public function test_owner_can_delete_their_review_and_the_average_updates(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $mine    = Review::factory()->create(['product_id' => $product->id, 'user_id' => $user->id, 'rating' => 1]);
        Review::factory()->create(['product_id' => $product->id, 'rating' => 5]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/reviews/{$mine->id}")
            ->assertOk();

        $this->assertDatabaseMissing('reviews', ['id' => $mine->id]);

        $response = $this->getJson("/api/products/{$product->id}/reviews");
        $this->assertEquals(5, $response->json('average_rating'));
        $this->assertSame(1, $response->json('total_reviews'));
    }

    public function test_after_deleting_a_review_the_user_can_review_again(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        $this->purchase($user, $product);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 2])
            ->assertCreated();

        $review = Review::first();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/reviews/{$review->id}")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/reviews", ['rating' => 5])
            ->assertCreated();
    }
}