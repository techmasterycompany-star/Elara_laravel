<?php
// tests/Feature/Catalog/ProductManageTest.php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'name'        => 'Glow Serum',
            'description' => 'A nice serum',
            'price'       => 500,
            'stock'       => 10,
            'sku'         => 'GLOW-001',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // POST /api/products  (store)
    // ------------------------------------------------------------------

    public function test_admin_creates_an_active_product_with_no_seller(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/products', $this->payload())
            ->assertCreated();

        $response->assertJsonPath('product.status', 'active')
            ->assertJsonPath('product.seller_id', null)
            ->assertJsonPath('product.slug', 'glow-serum');

        $this->assertDatabaseHas('products', ['sku' => 'GLOW-001']);
    }

    public function test_approved_seller_creates_a_pending_product_linked_to_their_store(): void
    {
        $seller = Seller::factory()->create();

        $this->actingAs($seller->user, 'sanctum')
            ->postJson('/api/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('product.status', 'pending')
            ->assertJsonPath('product.seller_id', $seller->id);
    }

    public function test_pending_seller_cannot_create_products(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($seller->user, 'sanctum')
            ->postJson('/api/products', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_seller_role_without_a_store_profile_cannot_create_products(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', $this->payload())
            ->assertForbidden();
    }

    public function test_customer_and_guest_cannot_create_products(): void
    {
        $this->postJson('/api/products', $this->payload())->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/products', $this->payload())
            ->assertForbidden();
    }

    public function test_store_validates_sale_price_and_unique_sku(): void
    {
        Product::factory()->create(['sku' => 'TAKEN-1']);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $this->payload(['price' => 100, 'sale_price' => 100]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sale_price');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $this->payload(['sku' => 'TAKEN-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'name', 'price', 'stock', 'sku']);
    }

    public function test_products_with_the_same_name_get_unique_slugs(): void
    {
        $admin = $this->admin();
        $categoryId = Category::factory()->create()->id;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $this->payload(['category_id' => $categoryId, 'sku' => 'A-1']))
            ->assertCreated()
            ->assertJsonPath('product.slug', 'glow-serum');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $this->payload(['category_id' => $categoryId, 'sku' => 'A-2']))
            ->assertCreated()
            ->assertJsonPath('product.slug', 'glow-serum-1');
    }

    public function test_store_saves_uploaded_images(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/products', $this->payload([
                'images' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ]))
            ->assertCreated();

        $this->assertCount(2, $response->json('product.images'));
        $this->assertDatabaseCount('product_images', 2);
    }

    public function test_store_rejects_more_than_five_images(): void
    {
        Storage::fake('public');

        $images = array_map(fn ($i) => UploadedFile::fake()->image("img{$i}.jpg"), range(1, 6));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/products', $this->payload(['images' => $images]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    }

    // ------------------------------------------------------------------
    // PUT /api/products/{product}  (update)
    // ------------------------------------------------------------------

    public function test_admin_and_owner_can_update_but_another_seller_cannot(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $owner->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['description' => 'by admin'])
            ->assertOk();

        $this->actingAs($owner->user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['description' => 'by owner'])
            ->assertOk();

        $this->actingAs($other->user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['description' => 'hacked'])
            ->assertForbidden();

        $this->assertSame('by owner', $product->fresh()->description);
    }

    public function test_seller_cannot_update_an_admin_owned_product(): void
    {
        $seller  = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => null]);

        $this->actingAs($seller->user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['name' => 'Mine now'])
            ->assertForbidden();
    }

    public function test_changing_the_name_regenerates_the_slug(): void
    {
        $product = Product::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['name' => 'Brand New Name'])
            ->assertOk();

        $this->assertSame('brand-new-name', $product->fresh()->slug);
    }

    public function test_update_cannot_push_price_to_or_below_the_existing_sale_price(): void
    {
        $product = Product::factory()->create(['price' => 1000, 'sale_price' => 800]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 700])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertEquals(1000, $product->fresh()->price);
    }

    public function test_update_rejects_a_sale_price_not_below_the_price(): void
    {
        $product = Product::factory()->create(['price' => 1000]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['sale_price' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sale_price');
    }

    public function test_update_allows_keeping_the_same_sku(): void
    {
        $product = Product::factory()->create(['sku' => 'KEEP-ME']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['sku' => 'KEEP-ME', 'description' => 'x'])
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // PATCH /api/products/{product}/status
    // ------------------------------------------------------------------

    public function test_admin_can_set_any_valid_status(): void
    {
        $admin   = $this->admin();
        $product = Product::factory()->create();

        foreach (['pending', 'hidden', 'rejected', 'active'] as $status) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/products/{$product->id}/status", ['status' => $status])
                ->assertOk();

            $this->assertSame($status, $product->fresh()->status);
        }
    }

    public function test_status_rejects_an_unknown_value(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/products/{$product->id}/status", ['status' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_seller_can_toggle_own_active_product_between_active_and_hidden(): void
    {
        $seller  = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);

        $this->actingAs($seller->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}/status", ['status' => 'hidden'])
            ->assertOk();
        $this->assertSame('hidden', $product->fresh()->status);

        $this->actingAs($seller->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}/status", ['status' => 'active'])
            ->assertOk();
        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_seller_cannot_self_approve_a_pending_or_rejected_product(): void
    {
        $seller = Seller::factory()->create();

        foreach (['pending', 'rejected'] as $status) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => $status]);

            $this->actingAs($seller->user, 'sanctum')
                ->patchJson("/api/products/{$product->id}/status", ['status' => 'active'])
                ->assertForbidden();

            $this->assertSame($status, $product->fresh()->status);
        }
    }

    public function test_seller_cannot_set_pending_or_rejected_status(): void
    {
        $seller  = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);

        foreach (['pending', 'rejected'] as $status) {
            $this->actingAs($seller->user, 'sanctum')
                ->patchJson("/api/products/{$product->id}/status", ['status' => $status])
                ->assertForbidden();
        }

        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_seller_cannot_change_status_of_another_sellers_product(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $owner->id, 'status' => 'active']);

        $this->actingAs($other->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}/status", ['status' => 'hidden'])
            ->assertForbidden();

        $this->assertSame('active', $product->fresh()->status);
    }

    // ------------------------------------------------------------------
    // PATCH /api/products/{product}/stock
    // ------------------------------------------------------------------

    public function test_stock_can_be_set_to_an_exact_value(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'set', 'quantity' => 42])
            ->assertOk();

        $this->assertSame(42, $product->fresh()->stock);
    }

    public function test_stock_can_be_adjusted_up_and_down(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $admin   = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'adjust', 'quantity' => 5])
            ->assertOk();
        $this->assertSame(15, $product->fresh()->stock);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'adjust', 'quantity' => -15])
            ->assertOk();
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_stock_cannot_go_negative(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $admin   = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'adjust', 'quantity' => -4])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'set', 'quantity' => -1])
            ->assertStatus(422);

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_stock_endpoint_validates_mode_and_ownership(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $owner->id, 'stock' => 10]);

        $this->actingAs($owner->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'multiply', 'quantity' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');

        $this->actingAs($other->user, 'sanctum')
            ->patchJson("/api/products/{$product->id}/stock", ['mode' => 'set', 'quantity' => 999])
            ->assertForbidden();

        $this->assertSame(10, $product->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // DELETE /api/products/{product}
    // ------------------------------------------------------------------

    public function test_admin_soft_deletes_a_product_and_it_disappears_from_the_catalog(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $this->getJson("/api/products/{$product->id}")->assertNotFound();
        $this->getJson('/api/products')->assertJsonPath('total', 0);
    }

    public function test_seller_cannot_delete_another_sellers_product(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $owner->id]);

        $this->actingAs($other->user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);

        $this->actingAs($owner->user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Images: POST /{product}/images , DELETE /{product}/images/{image}
    // ------------------------------------------------------------------

    public function test_owner_can_add_images_with_incrementing_sort_order(): void
    {
        Storage::fake('public');

        $seller  = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/old.jpg', 'sort_order' => 0]);

        $this->actingAs($seller->user, 'sanctum')
            ->postJson("/api/products/{$product->id}/images", [
                'images' => [UploadedFile::fake()->image('n1.jpg'), UploadedFile::fake()->image('n2.jpg')],
            ])
            ->assertCreated();

        $this->assertEquals([0, 1, 2], $product->images()->pluck('sort_order')->all());
    }

    public function test_product_cannot_exceed_five_images_in_total(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create();
        foreach (range(0, 3) as $i) {
            ProductImage::create(['product_id' => $product->id, 'path' => "products/{$i}.jpg", 'sort_order' => $i]);
        }

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/products/{$product->id}/images", [
                'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ])
            ->assertStatus(422);

        $this->assertSame(4, $product->images()->count());
    }

    public function test_another_seller_cannot_add_images(): void
    {
        Storage::fake('public');

        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $owner->id]);

        $this->actingAs($other->user, 'sanctum')
            ->postJson("/api/products/{$product->id}/images", [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertForbidden();
    }

    public function test_owner_can_delete_an_image(): void
    {
        Storage::fake('public');

        $seller  = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $image   = ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.jpg', 'sort_order' => 0]);

        $this->actingAs($seller->user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}/images/{$image->id}")
            ->assertOk();

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    public function test_image_of_a_different_product_cannot_be_deleted_through_this_product(): void
    {
        $admin    = $this->admin();
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $imageOfB = ProductImage::create(['product_id' => $productB->id, 'path' => 'products/b.jpg', 'sort_order' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/products/{$productA->id}/images/{$imageOfB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $imageOfB->id]);
    }
}