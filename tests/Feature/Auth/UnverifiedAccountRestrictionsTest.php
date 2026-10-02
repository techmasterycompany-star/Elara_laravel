<?php
// tests/Feature/Auth/UnverifiedAccountRestrictionsTest.php

namespace Tests\Feature\Auth;

use App\Models\Seller;
use App\Models\SellerPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnverifiedAccountRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_with_an_email_still_returns_a_token_and_starts_unverified(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'New User',
            'email'                 => 'new@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonStructure(['user', 'token']);

        $this->assertNull(User::where('email', 'new@example.com')->first()->email_verified_at);
    }

    public function test_unverified_user_can_still_resend_the_verification_mail(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/resend')
            ->assertOk();
    }

    public function test_unverified_user_cannot_register_as_a_seller(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'My Store'])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');

        $this->assertSame(0, Seller::count());
    }

    public function test_verified_user_can_register_as_a_seller(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/register', ['store_name' => 'My Store'])
            ->assertCreated();
    }

    public function test_unverified_seller_cannot_request_a_payout(): void
    {
        $user   = User::factory()->unverified()->create(['role' => 'seller']);
        $seller = Seller::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/payouts', ['amount' => 10])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');

        $this->assertSame(0, SellerPayout::where('seller_id', $seller->id)->count());
    }

    public function test_unverified_seller_can_still_read_their_payouts_and_earnings(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'seller']);
        Seller::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/seller/payouts')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/seller/earnings')->assertOk();
    }

    public function test_verified_seller_gets_past_the_verification_check(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        Seller::factory()->create(['user_id' => $user->id]);

        // No balance, so the request fails on the amount (422), not on verification (403).
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/seller/payouts', ['amount' => 10])
            ->assertStatus(422);
    }

    public function test_unverified_user_cannot_use_saved_payment_methods(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/payment-methods')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/payment-methods/setup-intent')->assertForbidden();
    }

    public function test_the_verification_error_is_json_even_without_an_accept_header(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user, 'sanctum')
            ->post('/api/seller/register', ['store_name' => 'My Store'], ['Accept' => 'text/html'])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
    }
}
