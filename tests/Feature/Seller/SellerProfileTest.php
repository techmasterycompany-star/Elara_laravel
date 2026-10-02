<?php
// tests/Feature/Seller/SellerProfileTest.php

namespace Tests\Feature\Seller;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function sellerNamed(string $name, string $slug, string $status = 'approved'): Seller
    {
        return Seller::factory()->create([
            'store_name' => $name,
            'store_slug' => $slug,
            'status'     => $status,
        ]);
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_get_401_on_profile_endpoints(): void
    {
        $this->getJson('/api/seller/profile')->assertUnauthorized();
        $this->putJson('/api/seller/profile', ['store_name' => 'X'])->assertUnauthorized();
    }

    public function test_customers_and_admins_get_403_on_profile_endpoints(): void
    {
        foreach (['customer', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')->getJson('/api/seller/profile')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->putJson('/api/seller/profile', ['store_name' => 'X'])
                ->assertForbidden();
        }
    }

    public function test_seller_role_without_a_profile_gets_404(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->actingAs($user, 'sanctum')->getJson('/api/seller/profile')->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => 'X'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // GET /api/seller/profile
    // ------------------------------------------------------------------

    public function test_seller_sees_only_their_own_profile(): void
    {
        $mine  = $this->sellerNamed('Mine', 'mine');
        $other = $this->sellerNamed('Other', 'other');

        $this->actingAs($mine->user, 'sanctum')
            ->getJson('/api/seller/profile')
            ->assertOk()
            ->assertJsonPath('seller.id', $mine->id)
            ->assertJsonPath('seller.store_name', 'Mine')
            ->assertJsonMissing(['store_name' => $other->store_name]);
    }

    public function test_pending_seller_can_view_their_profile(): void
    {
        $seller = $this->sellerNamed('Pending Store', 'pending-store', 'pending');

        $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/profile')
            ->assertOk()
            ->assertJsonPath('seller.status', 'pending');
    }

    // ------------------------------------------------------------------
    // PUT /api/seller/profile
    // ------------------------------------------------------------------

    public function test_seller_can_update_name_and_description_and_slug_follows_name(): void
    {
        $seller = $this->sellerNamed('Old Name', 'old-name');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', [
                'store_name'  => 'New Name',
                'description' => 'Fresh description',
            ])
            ->assertOk()
            ->assertJsonPath('seller.store_name', 'New Name')
            ->assertJsonPath('seller.store_slug', 'new-name')
            ->assertJsonPath('seller.description', 'Fresh description');

        $this->assertDatabaseHas('sellers', [
            'id'         => $seller->id,
            'store_name' => 'New Name',
            'store_slug' => 'new-name',
        ]);
    }

    public function test_updating_only_description_keeps_name_and_slug(): void
    {
        $seller = $this->sellerNamed('Stable Store', 'stable-store');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['description' => 'Only this changed'])
            ->assertOk()
            ->assertJsonPath('seller.store_name', 'Stable Store')
            ->assertJsonPath('seller.store_slug', 'stable-store')
            ->assertJsonPath('seller.description', 'Only this changed');
    }

    public function test_sending_the_same_store_name_keeps_the_slug(): void
    {
        $seller = $this->sellerNamed('Same Store', 'same-store');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => 'Same Store'])
            ->assertOk()
            ->assertJsonPath('seller.store_slug', 'same-store');
    }

    public function test_description_can_be_cleared_with_null(): void
    {
        $seller = $this->sellerNamed('Clear Store', 'clear-store');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['description' => null])
            ->assertOk()
            ->assertJsonPath('seller.description', null);
    }

    public function test_renaming_to_a_name_whose_slug_is_taken_gets_a_suffix(): void
    {
        $this->sellerNamed('Taken', 'taken');
        $seller = $this->sellerNamed('Mine', 'mine');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => 'Taken'])
            ->assertOk()
            ->assertJsonPath('seller.store_slug', 'taken-1');
    }

    public function test_store_name_validation_on_update(): void
    {
        $seller = $this->sellerNamed('Valid Name', 'valid-name');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_name');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_name');

        $this->assertSame('Valid Name', $seller->fresh()->store_name);
    }

    public function test_seller_cannot_change_status_owner_or_slug_through_payload(): void
    {
        $seller = $this->sellerNamed('Locked Store', 'locked-store', 'pending');
        $other  = User::factory()->create();

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', [
                'description'    => 'ok',
                'status'         => 'approved',
                'user_id'        => $other->id,
                'store_slug'     => 'hijacked',
                'payout_details' => ['iban' => 'X'],
            ])
            ->assertOk();

        $fresh = $seller->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($seller->user_id, $fresh->user_id);
        $this->assertSame('locked-store', $fresh->store_slug);
        $this->assertNull($fresh->payout_details);
    }

    public function test_seller_cannot_touch_another_sellers_profile(): void
    {
        $mine  = $this->sellerNamed('Mine', 'mine');
        $other = $this->sellerNamed('Other', 'other');

        $this->actingAs($mine->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Other', $other->fresh()->store_name);
        $this->assertSame('other', $other->fresh()->store_slug);
    }

    // ------------------------------------------------------------------
    // BUG (found by reading the code): changing only the CASE of the name
    // ("My Store" -> "my store") makes generateUniqueSlug() collide with the
    // seller's OWN current slug, so it becomes "my-store-1" and the store URL
    // changes for no reason.
    // Expected after the fix: slug stays "my-store".
    // This test is DESIGNED TO FAIL until the controller is fixed.
    // ------------------------------------------------------------------

    public function test_case_only_rename_does_not_change_the_slug(): void
    {
        $seller = $this->sellerNamed('My Store', 'my-store');

        $this->actingAs($seller->user, 'sanctum')
            ->putJson('/api/seller/profile', ['store_name' => 'my store'])
            ->assertOk()
            ->assertJsonPath('seller.store_slug', 'my-store');
    }
}
