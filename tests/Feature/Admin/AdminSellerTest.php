<?php
// tests/Feature/Admin/AdminSellerTest.php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSellerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function url(Seller $seller): string
    {
        return "/api/admin/sellers/{$seller->id}/status";
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guest_gets_401(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->patchJson($this->url($seller), ['status' => 'approved'])->assertUnauthorized();

        $this->assertSame('pending', $seller->fresh()->status);
    }

    public function test_customers_and_sellers_get_403_and_status_is_unchanged(): void
    {
        $seller = Seller::factory()->pending()->create();

        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'approved'])
            ->assertForbidden();

        // A seller must not be able to approve their own store.
        $this->actingAs($seller->user, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $seller->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Approve / reject
    // ------------------------------------------------------------------

    public function test_admin_can_approve_a_pending_seller(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('message', 'Seller approved successfully.')
            ->assertJsonPath('seller.status', 'approved');

        $this->assertSame('approved', $seller->fresh()->status);
    }

    public function test_admin_can_reject_a_pending_seller(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('message', 'Seller rejected successfully.')
            ->assertJsonPath('seller.status', 'rejected');

        $this->assertSame('rejected', $seller->fresh()->status);
    }

    public function test_admin_can_revoke_an_approved_seller_and_re_approve_a_rejected_one(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $admin  = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'rejected'])
            ->assertOk();
        $this->assertSame('rejected', $seller->fresh()->status);

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'approved'])
            ->assertOk();
        $this->assertSame('approved', $seller->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Validation / not found
    // ------------------------------------------------------------------

    public function test_status_must_be_approved_or_rejected(): void
    {
        $seller = Seller::factory()->pending()->create();
        $admin  = $this->admin();

        foreach ([[], ['status' => 'pending'], ['status' => 'garbage'], ['status' => ['approved']]] as $payload) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson($this->url($seller), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        $this->assertSame('pending', $seller->fresh()->status);
    }

    public function test_unknown_seller_returns_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/sellers/999999/status', ['status' => 'approved'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Side effects
    // ------------------------------------------------------------------

    public function test_only_status_changes_and_other_sellers_are_untouched(): void
    {
        $seller = Seller::factory()->pending()->create([
            'store_name' => 'Target Store',
            'store_slug' => 'target-store',
        ]);
        $other = Seller::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($seller), [
                'status'     => 'approved',
                'store_name' => 'Hacked Name',
                'user_id'    => $other->user_id,
            ])
            ->assertOk();

        $fresh = $seller->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('Target Store', $fresh->store_name);
        $this->assertSame('target-store', $fresh->store_slug);
        $this->assertSame($seller->user_id, $fresh->user_id);
        $this->assertSame('seller', $fresh->user->role);

        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_approval_unlocks_product_listing_and_rejection_locks_it_again(): void
    {
        $seller   = Seller::factory()->pending()->create();
        $admin    = $this->admin();
        $category = Category::factory()->create();

        $payload = fn (string $sku) => [
            'category_id' => $category->id,
            'name'        => 'Glow Serum',
            'description' => 'A nice serum',
            'price'       => 500,
            'stock'       => 10,
            'sku'         => $sku,
        ];

        $this->actingAs($seller->user, 'sanctum')
            ->postJson('/api/products', $payload('SKU-1'))
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'approved'])
            ->assertOk();

        $this->actingAs($seller->user->fresh(), 'sanctum')
            ->postJson('/api/products', $payload('SKU-2'))
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->patchJson($this->url($seller), ['status' => 'rejected'])
            ->assertOk();

        $this->actingAs($seller->user->fresh(), 'sanctum')
            ->postJson('/api/products', $payload('SKU-3'))
            ->assertForbidden();

        $this->assertSame(1, $seller->products()->count());
    }
}
