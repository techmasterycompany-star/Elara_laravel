<?php
// tests/Feature/Admin/AdminProductTest.php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Sorted ids of the products returned in the paginated "data" array. */
    private function idsOf($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_get_401_on_admin_product_endpoints(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
        $this->patchJson('/api/admin/products/bulk-status', [])->assertUnauthorized();
    }

    public function test_customers_and_sellers_get_403_and_bulk_status_changes_nothing(): void
    {
        $product = Product::factory()->pending()->create();

        foreach (['customer', 'seller'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')->getJson('/api/admin/products')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->patchJson('/api/admin/products/bulk-status', [
                    'product_ids' => [$product->id],
                    'status'      => 'active',
                ])
                ->assertForbidden();
        }

        $this->assertSame('pending', $product->fresh()->status);
    }

    // ------------------------------------------------------------------
    // GET /api/admin/products  (index)
    // ------------------------------------------------------------------

    public function test_index_returns_products_of_every_status(): void
    {
        $active   = Product::factory()->create();
        $pending  = Product::factory()->pending()->create();
        $hidden   = Product::factory()->create(['status' => 'hidden']);
        $rejected = Product::factory()->create(['status' => 'rejected']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/products')
            ->assertOk();

        $this->assertSame(
            collect([$active, $pending, $hidden, $rejected])->pluck('id')->sort()->values()->all(),
            $this->idsOf($response)
        );
    }

    public function test_index_includes_relations(): void
    {
        $seller = Seller::factory()->create();
        Product::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'status', 'category', 'seller', 'images']]]);
    }

    public function test_index_excludes_soft_deleted_products(): void
    {
        $live    = Product::factory()->create();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/products');

        $this->assertSame([$live->id], $this->idsOf($response));
    }

    public function test_index_is_newest_first(): void
    {
        $old = Product::factory()->create(['created_at' => now()->subDays(3)]);
        $new = Product::factory()->create(['created_at' => now()->subDay()]);
        $mid = Product::factory()->create(['created_at' => now()->subDays(2)]);

        $ids = collect(
            $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/products')->json('data')
        )->pluck('id')->all();

        $this->assertSame([$new->id, $mid->id, $old->id], $ids);
    }

    public function test_index_paginates_20_per_page(): void
    {
        Product::factory()->count(25)->create();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('total', 25)
            ->assertJsonCount(20, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        Product::factory()->create();
        $pending = Product::factory()->pending()->create();
        Product::factory()->create(['status' => 'rejected']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/products?status=pending');

        $this->assertSame([$pending->id], $this->idsOf($response));
    }

    public function test_index_filters_by_category(): void
    {
        $category = Category::factory()->create();
        $inside   = Product::factory()->create(['category_id' => $category->id]);
        Product::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/products?category_id={$category->id}");

        $this->assertSame([$inside->id], $this->idsOf($response));
    }

    public function test_index_filters_by_seller(): void
    {
        $seller = Seller::factory()->create();
        $mine   = Product::factory()->create(['seller_id' => $seller->id]);
        Product::factory()->create(['seller_id' => Seller::factory()->create()->id]);
        Product::factory()->create(); // admin-owned, seller_id = null

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/products?seller_id={$seller->id}");

        $this->assertSame([$mine->id], $this->idsOf($response));
    }

    public function test_index_searches_by_name(): void
    {
        $a = Product::factory()->create(['name' => 'Rose Serum']);
        $b = Product::factory()->create(['name' => 'Vitamin C Serum']);
        Product::factory()->create(['name' => 'Rose Cream']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/products?search=Serum');

        $this->assertSame(collect([$a, $b])->pluck('id')->sort()->values()->all(), $this->idsOf($response));
    }

    public function test_index_combines_filters(): void
    {
        $seller = Seller::factory()->create();

        $match = Product::factory()->pending()->create(['seller_id' => $seller->id, 'name' => 'Rose Serum']);
        Product::factory()->create(['seller_id' => $seller->id, 'name' => 'Rose Serum']);            // wrong status
        Product::factory()->pending()->create(['seller_id' => $seller->id, 'name' => 'Rose Cream']); // wrong name
        Product::factory()->pending()->create(['name' => 'Rose Serum']);                             // wrong seller

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/products?status=pending&seller_id={$seller->id}&search=Serum");

        $this->assertSame([$match->id], $this->idsOf($response));
    }

    public function test_index_validates_filters(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products?status=garbage')
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products?category_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products?seller_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('seller_id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products?search='.str_repeat('a', 256))
            ->assertStatus(422)->assertJsonValidationErrors('search');
    }

    // ------------------------------------------------------------------
    // PATCH /api/admin/products/bulk-status
    // ------------------------------------------------------------------

    public function test_bulk_status_approves_pending_products(): void
    {
        $seller = Seller::factory()->create();
        $a = Product::factory()->pending()->create(['seller_id' => $seller->id]);
        $b = Product::factory()->pending()->create(['seller_id' => $seller->id]);
        $c = Product::factory()->pending()->create(); // admin-owned

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/products/bulk-status', [
                'product_ids' => [$a->id, $b->id, $c->id],
                'status'      => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('message', '3 product(s) updated successfully.');

        foreach ([$a, $b, $c] as $product) {
            $this->assertSame('active', $product->fresh()->status);
        }
    }

    public function test_bulk_status_can_hide_and_reject(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->pending()->create();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/products/bulk-status', ['product_ids' => [$a->id], 'status' => 'hidden'])
            ->assertOk();
        $this->assertSame('hidden', $a->fresh()->status);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/products/bulk-status', ['product_ids' => [$b->id], 'status' => 'rejected'])
            ->assertOk();
        $this->assertSame('rejected', $b->fresh()->status);
    }

    public function test_bulk_status_only_touches_the_given_ids(): void
    {
        $target    = Product::factory()->pending()->create();
        $untouched = Product::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/products/bulk-status', [
                'product_ids' => [$target->id],
                'status'      => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('message', '1 product(s) updated successfully.');

        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame('pending', $untouched->fresh()->status);
    }

    public function test_bulk_status_rejects_invalid_status(): void
    {
        $product = Product::factory()->pending()->create();
        $admin   = $this->admin();

        foreach ([[], ['status' => 'pending'], ['status' => 'garbage']] as $extra) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson('/api/admin/products/bulk-status', array_merge(['product_ids' => [$product->id]], $extra))
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        $this->assertSame('pending', $product->fresh()->status);
    }

    public function test_bulk_status_rejects_invalid_product_ids(): void
    {
        $product = Product::factory()->pending()->create();
        $admin   = $this->admin();

        $cases = [
            'missing'      => [[], 'product_ids'],
            'empty'        => [['product_ids' => []], 'product_ids'],
            'not an array' => [['product_ids' => $product->id], 'product_ids'],
            'not integer'  => [['product_ids' => ['abc']], 'product_ids.0'],
            'nonexistent'  => [['product_ids' => [$product->id, 999999]], 'product_ids.1'],
        ];

        foreach ($cases as $label => [$payload, $errorKey]) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson('/api/admin/products/bulk-status', array_merge(['status' => 'active'], $payload))
                ->assertStatus(422)
                ->assertJsonValidationErrors($errorKey);
        }

        // Validation is all-or-nothing: the valid id in the "nonexistent" case was not updated.
        $this->assertSame('pending', $product->fresh()->status);
    }

    public function test_bulk_status_skips_soft_deleted_products(): void
    {
        $live    = Product::factory()->pending()->create();
        $deleted = Product::factory()->pending()->create();
        $deleted->delete();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/products/bulk-status', [
                'product_ids' => [$live->id, $deleted->id],
                'status'      => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('message', '1 product(s) updated successfully.');

        $this->assertSame('active', $live->fresh()->status);
        $this->assertSoftDeleted('products', ['id' => $deleted->id]);
        $this->assertSame('pending', Product::withTrashed()->find($deleted->id)->status);
    }
}
