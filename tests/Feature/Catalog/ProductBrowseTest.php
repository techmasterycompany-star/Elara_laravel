<?php
// tests/Feature/Catalog/ProductBrowseTest.php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function browse(array $query = [])
    {
        return $this->getJson('/api/products?' . http_build_query($query));
    }

    // ------------------------------------------------------------------
    // GET /api/products  (index)
    // ------------------------------------------------------------------

    public function test_lists_only_active_products(): void
    {
        Product::factory()->count(2)->create();                       // active
        Product::factory()->create(['status' => 'pending']);
        Product::factory()->create(['status' => 'hidden']);
        Product::factory()->create(['status' => 'rejected']);

        $this->browse()
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_search_matches_name_and_ranks_prefix_matches_first(): void
    {
        Product::factory()->create(['name' => 'Vitamin C Serum']);
        Product::factory()->create(['name' => 'Serum Booster']);
        Product::factory()->create(['name' => 'Hydrating Cream']);

        $response = $this->browse(['search' => 'serum'])->assertOk();

        $response->assertJsonCount(2, 'data');
        // "Serum Booster" يبدأ بكلمة البحث فلازم يطلع قبل "Vitamin C Serum"
        $this->assertSame('Serum Booster', $response->json('data.0.name'));
    }

    public function test_search_treats_percent_sign_literally(): void
    {
        Product::factory()->count(3)->create();

        // لو الـ escaping بايظ، '%' هتجيب كل المنتجات
        $this->browse(['search' => '%'])
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_category_filter_includes_subcategory_products(): void
    {
        $parent = Category::factory()->create();
        $child  = Category::factory()->create(['parent_id' => $parent->id]);
        $other  = Category::factory()->create();

        $inParent = Product::factory()->create(['category_id' => $parent->id]);
        $inChild  = Product::factory()->create(['category_id' => $child->id]);
        Product::factory()->create(['category_id' => $other->id]);

        $byParent = $this->browse(['category_id' => $parent->id])->assertOk();
        $this->assertEqualsCanonicalizing(
            [$inParent->id, $inChild->id],
            $byParent->json('data.*.id')
        );

        // الفلترة بالـ sub-category بترجّع منتجاتها هي بس
        $byChild = $this->browse(['category_id' => $child->id])->assertOk();
        $this->assertSame([$inChild->id], $byChild->json('data.*.id'));
    }

    public function test_unknown_category_id_is_rejected(): void
    {
        $this->browse(['category_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_price_filters_use_the_sale_price_when_present(): void
    {
        // السعر الفعلي = 300
        $onSale  = Product::factory()->create(['price' => 1000, 'sale_price' => 300]);
        // السعر الفعلي = 500
        $regular = Product::factory()->create(['price' => 500, 'sale_price' => null]);

        $min = $this->browse(['min_price' => 400])->assertOk();
        $this->assertSame([$regular->id], $min->json('data.*.id'));

        $max = $this->browse(['max_price' => 400])->assertOk();
        $this->assertSame([$onSale->id], $max->json('data.*.id'));
    }

    public function test_max_price_below_min_price_is_rejected(): void
    {
        $this->browse(['min_price' => 500, 'max_price' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_price');
    }

    public function test_in_stock_filter(): void
    {
        $available = Product::factory()->create(['stock' => 10]);
        $soldOut   = Product::factory()->outOfStock()->create();

        $in = $this->browse(['in_stock' => 1])->assertOk();
        $this->assertSame([$available->id], $in->json('data.*.id'));

        $out = $this->browse(['in_stock' => 0])->assertOk();
        $this->assertSame([$soldOut->id], $out->json('data.*.id'));
    }

    public function test_sorting_by_effective_price(): void
    {
        $a = Product::factory()->create(['price' => 300, 'sale_price' => null]); // 300
        $b = Product::factory()->create(['price' => 400, 'sale_price' => 50]);   // 50
        $c = Product::factory()->create(['price' => 200, 'sale_price' => null]); // 200

        $asc = $this->browse(['sort' => 'price_asc'])->assertOk();
        $this->assertSame([$b->id, $c->id, $a->id], $asc->json('data.*.id'));

        $desc = $this->browse(['sort' => 'price_desc'])->assertOk();
        $this->assertSame([$a->id, $c->id, $b->id], $desc->json('data.*.id'));
    }

    public function test_per_page_is_respected_and_capped(): void
    {
        Product::factory()->count(3)->create();

        $this->browse(['per_page' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3);

        $this->browse(['per_page' => 51])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->browse(['per_page' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_min_rating_filter(): void
    {
        [$u1, $u2] = User::factory()->count(2)->create();

        $loved  = Product::factory()->create();
        $meh    = Product::factory()->create();

        Review::create(['product_id' => $loved->id, 'user_id' => $u1->id, 'rating' => 5]);
        Review::create(['product_id' => $loved->id, 'user_id' => $u2->id, 'rating' => 5]);
        Review::create(['product_id' => $meh->id,   'user_id' => $u1->id, 'rating' => 2]);

        // لو التيست ده بالذات فشل بـ SQL error يبقى ده باج حقيقي في الـ having/withAvg مش في التيست
        $response = $this->browse(['min_rating' => 4])->assertOk();

        $this->assertSame([$loved->id], $response->json('data.*.id'));
    }

    // ------------------------------------------------------------------
    // GET /api/products/{product}  (show)
    // ------------------------------------------------------------------

    public function test_guest_can_view_an_active_product(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_guest_gets_404_for_any_non_active_product(): void
    {
        foreach (['pending', 'hidden', 'rejected'] as $status) {
            $product = Product::factory()->create(['status' => $status]);

            $this->getJson("/api/products/{$product->id}")->assertNotFound();
        }
    }

    public function test_customer_gets_404_for_a_hidden_product(): void
    {
        $customer = User::factory()->create();
        $product  = Product::factory()->create(['status' => 'hidden']);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_admin_can_view_a_non_active_product(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['status' => 'pending']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_seller_can_view_own_pending_product_but_not_another_sellers(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();

        $product = Product::factory()->create([
            'seller_id' => $owner->id,
            'status'    => 'pending',
        ]);

        $this->actingAs($owner->user, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertOk();

        $this->actingAs($other->user, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertNotFound();
    }
}