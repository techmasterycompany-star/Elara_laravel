<?php
// tests/Feature/Admin/AdminPayoutTest.php

namespace Tests\Feature\Admin;

use App\Models\Seller;
use App\Models\SellerPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function url(SellerPayout|int $payout): string
    {
        $id = $payout instanceof SellerPayout ? $payout->id : $payout;

        return "/api/admin/payouts/{$id}/mark-paid";
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guest_gets_401_and_payout_is_unchanged(): void
    {
        $payout = SellerPayout::factory()->create();

        $this->patchJson($this->url($payout))->assertUnauthorized();

        $this->assertSame('pending', $payout->fresh()->status);
        $this->assertNull($payout->fresh()->paid_at);
    }

    public function test_customers_and_sellers_get_403_and_payout_is_unchanged(): void
    {
        $payout = SellerPayout::factory()->create();

        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($payout))
            ->assertForbidden();

        // A seller must not be able to mark their own payout as paid.
        $this->actingAs($payout->seller->user, 'sanctum')
            ->patchJson($this->url($payout))
            ->assertForbidden();

        $this->assertSame('pending', $payout->fresh()->status);
        $this->assertNull($payout->fresh()->paid_at);
    }

    // ------------------------------------------------------------------
    // Mark as paid
    // ------------------------------------------------------------------

    public function test_admin_can_mark_a_pending_payout_as_paid(): void
    {
        $payout = SellerPayout::factory()->create(['amount' => 120.50]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($payout))
            ->assertOk()
            ->assertJsonPath('message', 'Payout marked as paid.')
            ->assertJsonPath('payout.id', $payout->id)
            ->assertJsonPath('payout.status', 'paid');

        $fresh = $payout->fresh();

        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_marking_as_paid_does_not_change_amount_or_seller(): void
    {
        $payout = SellerPayout::factory()->create(['amount' => 120.50]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($payout))
            ->assertOk();

        $fresh = $payout->fresh();

        $this->assertSame('120.50', (string) $fresh->amount);
        $this->assertSame($payout->seller_id, $fresh->seller_id);
    }

    public function test_paid_at_is_set_to_the_current_time(): void
    {
        $this->travelTo(now()->startOfSecond());

        $payout = SellerPayout::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($payout))
            ->assertOk();

        $this->assertTrue($payout->fresh()->paid_at->equalTo(now()));
    }

    public function test_marking_one_payout_does_not_touch_other_payouts(): void
    {
        $target = SellerPayout::factory()->create();
        $sameSeller = SellerPayout::factory()->create(['seller_id' => $target->seller_id]);
        $otherSeller = SellerPayout::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($target))
            ->assertOk();

        $this->assertSame('paid', $target->fresh()->status);
        $this->assertSame('pending', $sameSeller->fresh()->status);
        $this->assertSame('pending', $otherSeller->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Already paid / not found
    // ------------------------------------------------------------------

    public function test_an_already_paid_payout_gets_422_and_paid_at_is_kept(): void
    {
        $paidAt = now()->subDays(3)->startOfSecond();

        $payout = SellerPayout::factory()->create([
            'status'  => 'paid',
            'paid_at' => $paidAt,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url($payout))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This payout has already been paid.');

        $this->assertTrue($payout->fresh()->paid_at->equalTo($paidAt));
    }

    public function test_marking_paid_twice_in_a_row_only_works_the_first_time(): void
    {
        $payout = SellerPayout::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->patchJson($this->url($payout))->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson($this->url($payout))->assertStatus(422);
    }

    public function test_unknown_payout_gets_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url(999999))
            ->assertNotFound();
    }
}
