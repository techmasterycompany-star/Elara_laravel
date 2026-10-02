<?php
// tests/Feature/Payments/SellerPayoutTest.php

namespace Tests\Feature\Payments;

use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\SellerPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerPayoutTest extends TestCase
{
    use RefreshDatabase;

    /** A delivered (by default) order item of $seller worth $price x $qty. */
    private function item(Seller $seller, float $price, int $qty = 1, string $status = 'delivered', ?string $createdAt = null): OrderItem
    {
        $attributes = [
            'seller_id' => $seller->id,
            'price'     => $price,
            'quantity'  => $qty,
            'status'    => $status,
        ];

        if ($createdAt) {
            $attributes['created_at'] = $createdAt;
        }

        return OrderItem::factory()->create($attributes);
    }

    private function payout(Seller $seller, float $amount, string $status = 'pending', ?string $createdAt = null): SellerPayout
    {
        $attributes = ['seller_id' => $seller->id, 'amount' => $amount, 'status' => $status];

        if ($createdAt) {
            $attributes['created_at'] = $createdAt;
        }

        return SellerPayout::factory()->create($attributes);
    }

    private function request(Seller $seller, $amount)
    {
        return $this->actingAs($seller->user, 'sanctum')
            ->postJson('/api/seller/payouts', ['amount' => $amount]);
    }

    private function earnings(Seller $seller, string $query = '')
    {
        return $this->actingAs($seller->user, 'sanctum')->getJson('/api/seller/earnings'.$query);
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_get_401_on_every_payout_endpoint(): void
    {
        $this->getJson('/api/seller/earnings')->assertUnauthorized();
        $this->getJson('/api/seller/payouts')->assertUnauthorized();
        $this->postJson('/api/seller/payouts', ['amount' => 10])->assertUnauthorized();
    }

    public function test_customers_and_admins_get_403_on_every_payout_endpoint(): void
    {
        foreach (['customer', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')->getJson('/api/seller/earnings')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson('/api/seller/payouts')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/seller/payouts', ['amount' => 10])->assertForbidden();
        }

        $this->assertSame(0, SellerPayout::count());
    }

    public function test_seller_role_without_a_profile_gets_404_everywhere(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->actingAs($user, 'sanctum')->getJson('/api/seller/earnings')->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/seller/payouts')->assertNotFound();
        $this->actingAs($user, 'sanctum')->postJson('/api/seller/payouts', ['amount' => 10])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // GET /api/seller/earnings
    // ------------------------------------------------------------------

    public function test_earnings_count_only_this_sellers_delivered_items(): void
    {
        $seller = Seller::factory()->create();
        $other  = Seller::factory()->create();

        $this->item($seller, 100, 2);                       // 200 delivered
        $this->item($seller, 50, 1);                        // 50 delivered
        $this->item($seller, 999, 1, 'pending');            // not delivered
        $this->item($seller, 999, 1, 'shipped');            // not delivered
        $this->item($seller, 999, 1, 'cancelled');          // not delivered
        $this->item($other, 777, 1);                        // another seller

        $response = $this->earnings($seller)->assertOk();

        $this->assertSame(2, $response->json('delivered_items'));
        $this->assertEquals(250, $response->json('earnings_in_period'));
        $this->assertEquals(250, $response->json('available_balance'));
    }

    public function test_a_seller_with_no_sales_has_zero_everything(): void
    {
        $seller = Seller::factory()->create();

        $response = $this->earnings($seller)->assertOk();

        $this->assertSame(0, $response->json('delivered_items'));
        $this->assertEquals(0, $response->json('earnings_in_period'));
        $this->assertEquals(0, $response->json('available_balance'));
        $this->assertNull($response->json('period.from'));
        $this->assertNull($response->json('period.to'));
    }

    public function test_available_balance_subtracts_pending_and_paid_payouts_only(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 500);

        $this->payout($seller, 100, 'pending');
        $this->payout($seller, 150, 'paid');
        $this->payout($seller, 200, 'rejected'); // a payout that did not happen must not reduce the balance

        $this->assertEquals(250, $this->earnings($seller)->assertOk()->json('available_balance'));
    }

    public function test_available_balance_never_goes_below_zero(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100);
        $this->payout($seller, 150, 'paid');

        $this->assertEquals(0, $this->earnings($seller)->assertOk()->json('available_balance'));
    }

    public function test_date_filters_change_the_period_earnings_but_not_the_available_balance(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100, 1, 'delivered', '2026-01-10 12:00:00');
        $this->item($seller, 200, 1, 'delivered', '2026-02-10 12:00:00');

        $from = $this->earnings($seller, '?date_from=2026-02-01')->assertOk();
        $this->assertSame(1, $from->json('delivered_items'));
        $this->assertEquals(200, $from->json('earnings_in_period'));
        $this->assertEquals(300, $from->json('available_balance'));
        $this->assertSame('2026-02-01', $from->json('period.from'));

        $to = $this->earnings($seller, '?date_to=2026-01-31')->assertOk();
        $this->assertEquals(100, $to->json('earnings_in_period'));
        $this->assertEquals(300, $to->json('available_balance'));

        $both = $this->earnings($seller, '?date_from=2026-01-01&date_to=2026-12-31')->assertOk();
        $this->assertEquals(300, $both->json('earnings_in_period'));

        $none = $this->earnings($seller, '?date_from=2027-01-01')->assertOk();
        $this->assertSame(0, $none->json('delivered_items'));
        $this->assertEquals(0, $none->json('earnings_in_period'));
    }

    public function test_date_to_includes_the_whole_last_day(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 80, 1, 'delivered', '2026-03-05 23:30:00');

        $response = $this->earnings($seller, '?date_from=2026-03-05&date_to=2026-03-05')->assertOk();

        $this->assertSame(1, $response->json('delivered_items'));
        $this->assertEquals(80, $response->json('earnings_in_period'));
    }

    public function test_earnings_validates_the_dates(): void
    {
        $seller = Seller::factory()->create();

        $this->earnings($seller, '?date_from=not-a-date')
            ->assertStatus(422)->assertJsonValidationErrors('date_from');

        $this->earnings($seller, '?date_to=not-a-date')
            ->assertStatus(422)->assertJsonValidationErrors('date_to');

        $this->earnings($seller, '?date_from=2026-05-10&date_to=2026-05-01')
            ->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    // ------------------------------------------------------------------
    // POST /api/seller/payouts
    // ------------------------------------------------------------------

    public function test_seller_can_request_a_payout_within_their_balance(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100, 3); // 300

        $response = $this->request($seller, 100)
            ->assertCreated()
            ->assertJsonPath('message', 'Payout request submitted successfully.')
            ->assertJsonPath('payout.status', 'pending');

        $this->assertEquals(100, $response->json('payout.amount'));
        $this->assertDatabaseHas('seller_payouts', [
            'seller_id' => $seller->id,
            'amount'    => 100,
            'status'    => 'pending',
            'paid_at'   => null,
        ]);
        $this->assertEquals(200, $this->earnings($seller)->json('available_balance'));
    }

    public function test_seller_can_request_exactly_the_whole_balance(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 125, 2); // 250

        $this->request($seller, 250)->assertCreated();

        $this->assertEquals(0, $this->earnings($seller)->json('available_balance'));
    }

    public function test_amount_above_the_balance_is_rejected_and_nothing_is_created(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100);

        $this->request($seller, 100.01)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Requested amount exceeds your available balance.');

        $this->assertSame(0, SellerPayout::count());
    }

    public function test_a_seller_with_no_delivered_sales_cannot_request_a_payout(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 500, 1, 'pending');

        $this->request($seller, 1)->assertStatus(422);

        $this->assertSame(0, SellerPayout::count());
    }

    public function test_pending_requests_reduce_what_can_still_be_requested(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100); // balance 100

        $this->request($seller, 60)->assertCreated();
        $this->request($seller, 60)->assertStatus(422);   // only 40 left
        $this->request($seller, 40)->assertCreated();

        $this->assertSame(2, SellerPayout::count());
        $this->assertEquals(100, SellerPayout::sum('amount'));
    }

    public function test_paid_payouts_reduce_what_can_still_be_requested(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100);
        $this->payout($seller, 70, 'paid');

        $this->request($seller, 40)->assertStatus(422);
        $this->request($seller, 30)->assertCreated();
    }

    public function test_a_seller_cannot_withdraw_another_sellers_earnings(): void
    {
        $rich  = Seller::factory()->create();
        $broke = Seller::factory()->create();
        $this->item($rich, 1000);

        $this->request($broke, 10)->assertStatus(422);

        $this->assertSame(0, SellerPayout::where('seller_id', $broke->id)->count());
    }

    public function test_amount_is_validated(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 100);

        foreach ([null, 0, -5, 'abc', 0.001] as $bad) {
            $this->request($seller, $bad)
                ->assertStatus(422)
                ->assertJsonValidationErrors('amount');
        }

        $this->assertSame(0, SellerPayout::count());
    }

    /**
     * Characterisation of CURRENT behaviour: requestPayout() only checks that a seller profile
     * exists, not that it is approved. A pending/rejected store with delivered items can withdraw.
     */
    public function test_seller_approval_status_is_not_checked_when_requesting_a_payout(): void
    {
        $seller = Seller::factory()->pending()->create();
        $this->item($seller, 100);

        $this->request($seller, 50)->assertCreated();
    }

    // ------------------------------------------------------------------
    // BUG (found by reading the code): the balance is computed with PHP floats and compared
    // with ">" without rounding, so asking for the EXACT remaining balance can be refused.
    //   33.33 x 3 = 99.99 ;  99.99 - 50.00 = 49.98999999999999  ->  49.99 > 49.98999...  -> 422
    //   10.10 + 20.20 = 30.299999999999997 ; minus 10.00 -> 20.299999999999997 < 20.30  -> 422
    // These two tests are DESIGNED TO FAIL until the balance is rounded to cents.
    // ------------------------------------------------------------------

    public function test_seller_can_withdraw_the_exact_remaining_balance_after_a_payout(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 33.33, 3);          // earned 99.99
        $this->payout($seller, 50.00, 'paid');   // remaining 49.99

        $this->request($seller, 49.99)->assertCreated();
    }

    public function test_exact_remaining_balance_works_with_several_items(): void
    {
        $seller = Seller::factory()->create();
        $this->item($seller, 10.10);             // earned 30.30 in total
        $this->item($seller, 20.20);
        $this->payout($seller, 10.00, 'pending'); // remaining 20.30

        $this->request($seller, 20.30)->assertCreated();
    }

    // ------------------------------------------------------------------
    // GET /api/seller/payouts
    // ------------------------------------------------------------------

    public function test_payout_list_shows_only_my_payouts_newest_first(): void
    {
        $seller = Seller::factory()->create();
        $other  = Seller::factory()->create();

        $old = $this->payout($seller, 10, 'paid', '2026-01-01 10:00:00');
        $new = $this->payout($seller, 20, 'pending', '2026-03-01 10:00:00');
        $mid = $this->payout($seller, 30, 'pending', '2026-02-01 10:00:00');
        $this->payout($other, 99);

        $response = $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/payouts')
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->assertSame(
            [$new->id, $mid->id, $old->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_payout_list_is_paginated_20_per_page(): void
    {
        $seller = Seller::factory()->create();
        SellerPayout::factory()->count(25)->create(['seller_id' => $seller->id]);

        $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/payouts')
            ->assertOk()
            ->assertJsonPath('total', 25)
            ->assertJsonCount(20, 'data');

        $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/payouts?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_payout_list_is_empty_for_a_new_seller(): void
    {
        $seller = Seller::factory()->create();

        $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/payouts')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }
}
