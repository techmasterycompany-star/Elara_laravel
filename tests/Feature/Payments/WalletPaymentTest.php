<?php
// tests/Feature/Payments/WalletPaymentTest.php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function walletOrder(User $user, float $total): Order
    {
        return Order::factory()->create([
            'user_id'        => $user->id,
            'payment_method' => 'wallet',
            'status'         => 'pending_payment',
            'subtotal'       => $total,
            'shipping_fee'   => 0,
            'total'          => $total,
        ]);
    }

    private function pay(User $user, Order $order)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/orders/{$order->id}/pay");
    }

    public function test_wallet_payment_deducts_the_balance_and_marks_the_order_paid(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        $order = $this->walletOrder($user, 300);

        $this->pay($user, $order)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'paid');

        $this->assertEquals(200, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $order->fresh()->status);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('wallet', $payment->gateway);
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(300, (float) $payment->amount);
        $this->assertStringStartsWith('wallet-', $payment->gateway_transaction_id);
    }

    public function test_wallet_payment_works_when_the_balance_equals_the_total(): void
    {
        $user = User::factory()->create(['wallet_balance' => 99.99]);
        $order = $this->walletOrder($user, 99.99);

        $this->pay($user, $order)->assertOk()->assertJsonPath('success', true);

        $this->assertEquals(0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_insufficient_balance_fails_without_charging_anything(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50]);
        $order = $this->walletOrder($user, 300);

        // The endpoint answers 200 and reports the failure in the body.
        $this->pay($user, $order)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Insufficient wallet balance.');

        $this->assertEquals(50, (float) $user->fresh()->wallet_balance);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame('failed', Payment::where('order_id', $order->id)->value('status'));
    }

    public function test_order_can_be_paid_after_topping_up_a_failed_attempt(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50]);
        $order = $this->walletOrder($user, 300);

        $this->pay($user, $order)->assertJsonPath('success', false);

        $user->update(['wallet_balance' => 400]);

        $this->pay($user, $order)->assertOk()->assertJsonPath('success', true);

        $this->assertEquals(100, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame('paid', Payment::where('order_id', $order->id)->value('status'));
    }

    public function test_a_paid_wallet_order_is_never_charged_twice(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $order = $this->walletOrder($user, 300);

        $this->pay($user, $order)->assertOk();

        $this->pay($user, $order)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order has already been paid.');

        $this->assertEquals(700, (float) $user->fresh()->wallet_balance);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_guest_orders_cannot_use_the_wallet(): void
    {
        $order = Order::factory()->guest()->create(['payment_method' => 'wallet']);

        $this->postJson("/api/orders/{$order->id}/pay", [
            'order_number' => $order->order_number,
            'guest_email'  => $order->guest_email,
        ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'failed');

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_paying_charges_only_the_order_owner(): void
    {
        $owner = User::factory()->create(['wallet_balance' => 500]);
        $other = User::factory()->create(['wallet_balance' => 500]);
        $order = $this->walletOrder($owner, 300);

        // Someone else trying to pay with their own session is rejected.
        $this->pay($other, $order)->assertForbidden();

        $this->assertEquals(500, (float) $other->fresh()->wallet_balance);
        $this->assertEquals(500, (float) $owner->fresh()->wallet_balance);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_paid_from_the_wallet(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        $order = $this->walletOrder($user, 300);
        $order->update(['status' => 'cancelled']);

        $this->pay($user, $order)->assertStatus(422);

        $this->assertEquals(500, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payments', 0);
    }
}
