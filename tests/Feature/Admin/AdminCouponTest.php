<?php
// tests/Feature/Admin/AdminCouponTest.php

namespace Tests\Feature\Admin;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCouponTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code'           => 'WELCOME10',
            'discount_type'  => 'percent',
            'discount_value' => 10,
            'expires_at'     => now()->addMonth()->toDateString(),
            'usage_limit'    => 50,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_get_401_on_every_coupon_endpoint(): void
    {
        $coupon = Coupon::factory()->create();

        foreach ($this->endpoints($coupon) as [$method, $url]) {
            $this->json($method, $url, $this->validPayload(['code' => 'X1']))
                ->assertUnauthorized();
        }

        $this->assertSame(1, Coupon::count());
    }

    public function test_customers_and_sellers_get_403_on_every_coupon_endpoint(): void
    {
        $coupon = Coupon::factory()->create(['discount_value' => 50]);

        foreach (['customer', 'seller'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($this->endpoints($coupon) as [$method, $url]) {
                $this->actAs($user)->json($method, $url, $this->validPayload(['code' => 'X1']))
                    ->assertForbidden();
            }
        }

        $fresh = $coupon->fresh();

        $this->assertEquals(50, (float) $fresh->discount_value);
        $this->assertFalse($fresh->isExpired());
        $this->assertSame(1, Coupon::count());
    }

    private function endpoints(Coupon $coupon): array
    {
        return [
            ['GET',   '/api/admin/coupons'],
            ['POST',  '/api/admin/coupons'],
            ['PUT',   "/api/admin/coupons/{$coupon->id}"],
            ['PATCH', "/api/admin/coupons/{$coupon->id}/deactivate"],
            ['GET',   "/api/admin/coupons/{$coupon->id}/stats"],
        ];
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_is_paginated_20_per_page_newest_first(): void
    {
        foreach (range(1, 25) as $i) {
            Coupon::factory()->create(['created_at' => now()->subMinutes(30 - $i)]);
        }

        $newest = Coupon::orderByDesc('created_at')->first();

        $response = $this->actAs($this->admin())->getJson('/api/admin/coupons')->assertOk();

        $response->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('data.0.id', $newest->id);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_admin_can_create_a_coupon(): void
    {
        $this->actAs($this->admin())
            ->postJson('/api/admin/coupons', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Coupon created successfully.')
            ->assertJsonPath('coupon.code', 'WELCOME10');

        $coupon = Coupon::where('code', 'WELCOME10')->firstOrFail();

        $this->assertSame('percent', $coupon->discount_type);
        $this->assertEquals(10, (float) $coupon->discount_value);
        $this->assertSame(50, $coupon->usage_limit);
        $this->assertSame(0, (int) $coupon->used_count);
        $this->assertSame(now()->addMonth()->toDateString(), $coupon->expires_at->toDateString());
    }

    public function test_expiry_and_usage_limit_are_optional(): void
    {
        $this->actAs($this->admin())
            ->postJson('/api/admin/coupons', [
                'code'           => 'FOREVER',
                'discount_type'  => 'fixed',
                'discount_value' => 25,
            ])
            ->assertCreated();

        $coupon = Coupon::where('code', 'FOREVER')->firstOrFail();

        $this->assertNull($coupon->expires_at);
        $this->assertNull($coupon->usage_limit);
        $this->assertTrue($coupon->isValid());
    }

    public function test_create_validates_the_input(): void
    {
        $admin = $this->admin();
        Coupon::factory()->create(['code' => 'TAKEN']);

        $invalid = [
            'missing everything'     => [],
            'duplicate code'         => ['code' => 'TAKEN'],
            'code too long'          => ['code' => str_repeat('A', 51)],
            'unknown type'           => ['discount_type' => 'bogus'],
            'non numeric value'      => ['discount_value' => 'ten'],
            'negative value'         => ['discount_value' => -5],
            'expires today'          => ['expires_at' => now()->toDateString()],
            'expires in the past'    => ['expires_at' => now()->subDay()->toDateString()],
            'usage limit zero'       => ['usage_limit' => 0],
            'percent above 100'      => ['discount_type' => 'percent', 'discount_value' => 101],
        ];

        foreach ($invalid as $label => $override) {
            $payload = $label === 'missing everything' ? [] : $this->validPayload($override);

            $this->actAs($admin)
                ->postJson('/api/admin/coupons', $payload)
                ->assertStatus(422);
        }

        $this->assertSame(1, Coupon::count(), 'no invalid request may create a coupon');
    }

    public function test_percent_of_exactly_100_and_fixed_amounts_above_100_are_allowed(): void
    {
        $admin = $this->admin();

        $this->actAs($admin)->postJson('/api/admin/coupons', $this->validPayload([
            'code' => 'FREE', 'discount_type' => 'percent', 'discount_value' => 100,
        ]))->assertCreated();

        $this->actAs($admin)->postJson('/api/admin/coupons', $this->validPayload([
            'code' => 'BIG', 'discount_type' => 'fixed', 'discount_value' => 500,
        ]))->assertCreated();
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_admin_can_update_only_some_fields(): void
    {
        $coupon = Coupon::factory()->create([
            'code' => 'KEEPME', 'discount_type' => 'fixed', 'discount_value' => 50, 'usage_limit' => 100,
        ]);

        $this->actAs($this->admin())
            ->putJson("/api/admin/coupons/{$coupon->id}", ['discount_value' => 75])
            ->assertOk()
            ->assertJsonPath('message', 'Coupon updated successfully.');

        $fresh = $coupon->fresh();

        $this->assertEquals(75, (float) $fresh->discount_value);
        $this->assertSame('KEEPME', $fresh->code);
        $this->assertSame('fixed', $fresh->discount_type);
        $this->assertSame(100, $fresh->usage_limit);
    }

    public function test_a_coupon_can_keep_its_own_code_but_not_take_another_ones(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'MINE']);
        Coupon::factory()->create(['code' => 'THEIRS']);
        $admin = $this->admin();

        $this->actAs($admin)->putJson("/api/admin/coupons/{$coupon->id}", ['code' => 'MINE'])->assertOk();
        $this->actAs($admin)->putJson("/api/admin/coupons/{$coupon->id}", ['code' => 'THEIRS'])->assertStatus(422);

        $this->assertSame('MINE', $coupon->fresh()->code);
    }

    public function test_update_cannot_push_a_percent_coupon_above_100(): void
    {
        $admin = $this->admin();

        // Raising the value of a percent coupon.
        $percent = Coupon::factory()->percent(10)->create();
        $this->actAs($admin)->putJson("/api/admin/coupons/{$percent->id}", ['discount_value' => 150])->assertStatus(422);
        $this->assertEquals(10, (float) $percent->fresh()->discount_value);

        // Switching a big fixed coupon to percent without changing the value.
        $fixed = Coupon::factory()->create(['discount_type' => 'fixed', 'discount_value' => 500]);
        $this->actAs($admin)->putJson("/api/admin/coupons/{$fixed->id}", ['discount_type' => 'percent'])->assertStatus(422);
        $this->assertSame('fixed', $fixed->fresh()->discount_type);
    }

    public function test_update_can_remove_the_expiry_and_the_usage_limit(): void
    {
        $coupon = Coupon::factory()->create();

        $this->actAs($this->admin())
            ->putJson("/api/admin/coupons/{$coupon->id}", ['expires_at' => null, 'usage_limit' => null])
            ->assertOk();

        $fresh = $coupon->fresh();

        $this->assertNull($fresh->expires_at);
        $this->assertNull($fresh->usage_limit);
    }

    public function test_update_validates_the_input(): void
    {
        $coupon = Coupon::factory()->create(['discount_value' => 50]);
        $admin = $this->admin();

        foreach ([
            ['discount_type'  => 'bogus'],
            ['discount_value' => -1],
            ['discount_value' => 'abc'],
            ['usage_limit'    => 0],
            ['code'           => str_repeat('A', 51)],
        ] as $invalid) {
            $this->actAs($admin)->putJson("/api/admin/coupons/{$coupon->id}", $invalid)->assertStatus(422);
        }

        $this->assertEquals(50, (float) $coupon->fresh()->discount_value);
    }

    public function test_updating_or_deactivating_an_unknown_coupon_gets_404(): void
    {
        $admin = $this->admin();

        $this->actAs($admin)->putJson('/api/admin/coupons/999999', ['discount_value' => 5])->assertNotFound();
        $this->actAs($admin)->patchJson('/api/admin/coupons/999999/deactivate')->assertNotFound();
        $this->actAs($admin)->getJson('/api/admin/coupons/999999/stats')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Deactivate
    // ------------------------------------------------------------------

    public function test_deactivated_coupon_can_no_longer_be_applied_to_a_cart(): void
    {
        $coupon = Coupon::factory()->create();
        $this->assertTrue($coupon->isValid());

        $this->actAs($this->admin())
            ->patchJson("/api/admin/coupons/{$coupon->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Coupon deactivated successfully.');

        $this->assertTrue($coupon->fresh()->isExpired());

        $customer = User::factory()->create();

        $this->actAs($customer)
            ->postJson('/api/cart/coupon', ['code' => $coupon->code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This coupon code is invalid or expired.');
    }

    public function test_deactivating_twice_is_harmless(): void
    {
        $coupon = Coupon::factory()->create();
        $admin = $this->admin();

        $this->actAs($admin)->patchJson("/api/admin/coupons/{$coupon->id}/deactivate")->assertOk();
        $this->actAs($admin)->patchJson("/api/admin/coupons/{$coupon->id}/deactivate")->assertOk();

        $this->assertTrue($coupon->fresh()->isExpired());
    }

    // ------------------------------------------------------------------
    // Stats
    // ------------------------------------------------------------------

    public function test_stats_ignore_cancelled_orders(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 10, 'used_count' => 3]);

        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'paid', 'discount' => 50]);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'pending_payment', 'discount' => 30]);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'cancelled', 'discount' => 70]);
        Order::factory()->create(['coupon_id' => null, 'status' => 'paid', 'discount' => 999]);

        $response = $this->actAs($this->admin())
            ->getJson("/api/admin/coupons/{$coupon->id}/stats")
            ->assertOk()
            ->assertJsonPath('code', $coupon->code)
            ->assertJsonPath('usage_limit', 10)
            ->assertJsonPath('used_count', 3)
            ->assertJsonPath('remaining_uses', 7)
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('orders_count', 2);

        $this->assertEquals(80, (float) $response->json('total_discount_given'));
    }

    public function test_stats_for_an_unlimited_coupon_have_no_remaining_uses(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'used_count' => 4]);

        $this->actAs($this->admin())
            ->getJson("/api/admin/coupons/{$coupon->id}/stats")
            ->assertOk()
            ->assertJsonPath('remaining_uses', null)
            ->assertJsonPath('orders_count', 0);
    }

    public function test_stats_show_when_a_coupon_stopped_being_valid(): void
    {
        $admin = $this->admin();
        $expired = Coupon::factory()->expired()->create();
        $maxed = Coupon::factory()->maxedOut()->create();

        $this->actAs($admin)->getJson("/api/admin/coupons/{$expired->id}/stats")->assertJsonPath('is_valid', false);
        $this->actAs($admin)->getJson("/api/admin/coupons/{$maxed->id}/stats")
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('remaining_uses', 0);
    }
}
