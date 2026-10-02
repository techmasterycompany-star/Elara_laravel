<?php
// tests/Feature/Catalog/CategoryTest.php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ------------------------------------------------------------------
    // GET /api/categories
    // ------------------------------------------------------------------

    public function test_guest_sees_only_root_categories_with_their_children(): void
    {
        $root  = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        Category::factory()->create(); // root تاني من غير أولاد

        $response = $this->getJson('/api/categories')->assertOk();

        $response->assertJsonCount(2, 'categories');

        $rootJson = collect($response->json('categories'))->firstWhere('id', $root->id);
        $this->assertSame([$child->id], collect($rootJson['children'])->pluck('id')->all());

        // الـ child مش المفروض يظهر كـ root لوحده
        $this->assertNull(collect($response->json('categories'))->firstWhere('id', $child->id));
    }

    // ------------------------------------------------------------------
    // POST /api/categories  (store)
    // ------------------------------------------------------------------

    public function test_admin_creates_a_root_category_with_a_slug(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'Skin Care'])
            ->assertCreated()
            ->assertJsonPath('category.slug', 'skin-care')
            ->assertJsonPath('category.parent_id', null);
    }

    public function test_only_admin_can_create_categories(): void
    {
        $this->postJson('/api/categories', ['name' => 'X'])->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'X'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'seller']), 'sanctum')
            ->postJson('/api/categories', ['name' => 'X'])
            ->assertForbidden();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_store_requires_a_name_and_a_valid_parent(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Orphan', 'parent_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_admin_can_create_a_sub_category_under_a_root(): void
    {
        $root = Category::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'Serums', 'parent_id' => $root->id])
            ->assertCreated()
            ->assertJsonPath('category.parent_id', $root->id);
    }

    public function test_cannot_create_a_category_under_a_sub_category(): void
    {
        $root  = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/categories', ['name' => 'Too Deep', 'parent_id' => $child->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('categories', ['name' => 'Too Deep']);
    }

    public function test_categories_with_the_same_name_get_unique_slugs(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Hair Care'])
            ->assertCreated()
            ->assertJsonPath('category.slug', 'hair-care');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Hair Care'])
            ->assertCreated()
            ->assertJsonPath('category.slug', 'hair-care-1');
    }

    public function test_store_saves_the_uploaded_image_path(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/categories', [
                'name'  => 'With Image',
                'image' => UploadedFile::fake()->image('cat.jpg'),
            ])
            ->assertCreated();

        $path = Category::first()->image;

        $this->assertStringStartsWith('categories/', $path);
$this->assertTrue(Storage::disk('public')->exists($path));    }

    // ------------------------------------------------------------------
    // PUT /api/categories/{category}  (update)
    // ------------------------------------------------------------------

    public function test_category_cannot_be_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(422);

        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_cannot_move_a_category_under_a_sub_category(): void
    {
        $root  = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $other = Category::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/categories/{$other->id}", ['parent_id' => $child->id])
            ->assertStatus(422);

        $this->assertNull($other->fresh()->parent_id);
    }

    public function test_circular_parenting_is_blocked(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);

        // A تحت B وB تحت A = دايرة
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/categories/{$a->id}", ['parent_id' => $b->id])
            ->assertStatus(422);

        $this->assertNull($a->fresh()->parent_id);
    }

    public function test_a_category_that_has_children_cannot_become_a_sub_category(): void
    {
        $parent  = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $newRoot = Category::factory()->create();

        // يمنع تخطي مستويين: parent عنده أولاد مينفعش يتحط تحت حد
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/categories/{$parent->id}", ['parent_id' => $newRoot->id])
            ->assertStatus(422);

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_a_sub_category_can_be_moved_to_another_root_or_promoted_to_root(): void
    {
        $rootA = Category::factory()->create();
        $rootB = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $rootA->id]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/categories/{$child->id}", ['parent_id' => $rootB->id])
            ->assertOk();
        $this->assertSame($rootB->id, $child->fresh()->parent_id);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/categories/{$child->id}", ['parent_id' => null])
            ->assertOk();
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_renaming_regenerates_the_slug_and_keeping_the_same_name_is_fine(): void
    {
        $category = Category::factory()->create(['name' => 'Old', 'slug' => 'old']);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/categories/{$category->id}", ['name' => 'Fresh Name'])
            ->assertOk();
        $this->assertSame('fresh-name', $category->fresh()->slug);

        // نفس الاسم تاني مينفعش يبوظ على slug بتاعه هو
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/categories/{$category->id}", ['name' => 'Fresh Name'])
            ->assertOk();
    }

    public function test_only_admin_can_update_or_delete_categories(): void
    {
        $category = Category::factory()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->putJson("/api/categories/{$category->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    // ------------------------------------------------------------------
    // DELETE /api/categories/{category}
    // ------------------------------------------------------------------

    public function test_admin_can_delete_an_empty_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_deleting_a_parent_promotes_its_children_to_roots(): void
    {
        $parent = Category::factory()->create();
        $child  = Category::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/categories/{$parent->id}")
            ->assertOk();

        // الـ migration عامل nullOnDelete: الأولاد بيفضلوا موجودين كـ root
        $this->assertNull($child->fresh()->parent_id);
    }

    // ------------------------------------------------------------------
    // 🐞 تيستين المفروض يفشلوا دلوقتي: بيثبتوا باجين في الكود
    // ------------------------------------------------------------------

    /**
     * باج 1: الـ migration عامل restrictOnDelete على products.category_id
     * و destroy() مبيتأكدش من المنتجات، فالمتوقع حاليًا 500 بدل رسالة واضحة.
     */
    public function test_deleting_a_category_that_has_products_returns_422_not_500(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    /**
     * باج 2: update() بتعمل slug من الاسم من غير loop التفرد اللي في store()،
     * فتسمية تصنيف باسم تصنيف تاني المتوقع حاليًا 500 من unique على الـ slug.
     */
    public function test_renaming_to_an_existing_name_gets_a_unique_slug_instead_of_500(): void
    {
        Category::factory()->create(['name' => 'Skin Care', 'slug' => 'skin-care']);
        $other = Category::factory()->create(['name' => 'Body', 'slug' => 'body']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/categories/{$other->id}", ['name' => 'Skin Care'])
            ->assertOk();

        $this->assertSame('skin-care-1', $other->fresh()->slug);
    }
    public function test_a_soft_deleted_product_still_blocks_category_deletion(): void
{
    $category = Category::factory()->create();
    $product  = Product::factory()->create(['category_id' => $category->id]);
    $product->delete(); // soft delete

    $this->actingAs($this->admin(), 'sanctum')
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
}
}