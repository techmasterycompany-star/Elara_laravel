<?php
// tests/Feature/Seller/SellerTest.php

namespace Tests\Feature\Seller;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // POST /api/seller/register
    // ------------------------------------------------------------------

    public function test_guest_cannot_register_as_seller(): void
    {
        $this->postJson('/api/seller/register', ['store_name' => 'My Store'])
            ->assertUnauthorized();

        $this->assertSame(0, Seller::count());
    }

    public function test_customer_can_register_and_becomes_pending_seller(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', [
                'store_name'  => 'My Store',
                'description' => 'Natural cosmetics',
            ])
            ->assertCreated()
            ->assertJsonPath('seller.store_name', 'My Store')
            ->assertJsonPath('seller.store_slug', 'my-store')
            ->assertJsonPath('seller.status', 'pending');

        $this->assertDatabaseHas('sellers', [
            'user_id'    => $user->id,
            'store_slug' => 'my-store',
            'status'     => 'pending',
        ]);

        $this->assertSame('seller', $user->fresh()->role);
    }

    public function test_description_is_optional(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'No Description Store'])
            ->assertCreated()
            ->assertJsonPath('seller.description', null);
    }

    public function test_store_name_is_required_and_limited_to_255_chars(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_name');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_name');

        $this->assertSame(0, Seller::count());
        $this->assertSame('customer', $user->fresh()->role);
    }

    public function test_client_cannot_force_status_or_role_through_the_payload(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', [
                'store_name' => 'Sneaky Store',
                'status'     => 'approved',
                'role'       => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('seller.status', 'pending');

        $this->assertSame('seller', $user->fresh()->role);
    }

    public function test_user_with_existing_profile_gets_409_and_no_second_row(): void
    {
        $seller = Seller::factory()->create(['store_name' => 'First Store', 'store_slug' => 'first-store']);

        $this->actingAs($seller->user, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'Second Store'])
            ->assertStatus(409);

        $this->assertSame(1, Seller::where('user_id', $seller->user_id)->count());
        $this->assertSame('First Store', $seller->fresh()->store_name);
    }

    public function test_same_store_name_gets_a_unique_slug(): void
    {
        $first  = User::factory()->create(['role' => 'customer']);
        $second = User::factory()->create(['role' => 'customer']);
        $third  = User::factory()->create(['role' => 'customer']);

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'Glow Shop'])
            ->assertCreated()
            ->assertJsonPath('seller.store_slug', 'glow-shop');

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'Glow Shop'])
            ->assertCreated()
            ->assertJsonPath('seller.store_slug', 'glow-shop-1');

        $this->actingAs($third, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'Glow Shop'])
            ->assertCreated()
            ->assertJsonPath('seller.store_slug', 'glow-shop-2');
    }

    // ------------------------------------------------------------------
    // BUG: admin registering as seller loses the admin role.
    // Expected behaviour (after the fix): 403, role stays admin, no seller row.
    // This test is DESIGNED TO FAIL until the controller is fixed.
    // ------------------------------------------------------------------

    public function test_admin_cannot_register_as_seller_and_keeps_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'Admin Store'])
            ->assertForbidden();

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame(0, Seller::where('user_id', $admin->id)->count());
    }
}
