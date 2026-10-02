<?php
// tests/Feature/Payments/PayOrderTest.php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PayOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function codPayment(Order $order, string $status = 'pending'): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'gateway'  => 'cod',
            'amount'   => $order->total,
            'status'   => $status,
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/orders/{order}/pay  (cash on delivery)
    // ------------------------------------------------------------------

    public function test_owner_can_pay_a_cod_order_and_it_stays_waiting_for_cash(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_method' => 'cod']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'pending');

        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('cod', $payment->gateway);
        $this->assertSame('pending', $payment->status);
        $this->assertEquals((float) $order->total, (float) $payment->amount);

        // The order is only marked paid once the admin confirms the cash.
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_another_user_cannot_pay_my_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_guest_cannot_pay_a_registered_users_order(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/orders/{$order->id}/pay")->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_can_pay_their_order_with_the_right_number_and_email(): void
    {
        $order = Order::factory()->guest()->create();

        $this->postJson("/api/orders/{$order->id}/pay", [
            'order_number' => $order->order_number,
            'guest_email'  => $order->guest_email,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'gateway' => 'cod']);
    }

    public function test_guest_with_the_wrong_email_gets_403(): void
    {
        $order = Order::factory()->guest()->create();

        $this->postJson("/api/orders/{$order->id}/pay", [
            'order_number' => $order->order_number,
            'guest_email'  => 'someone.else@example.com',
        ])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_without_order_number_and_email_gets_422(): void
    {
        $order = Order::factory()->guest()->create();

        $this->postJson("/api/orders/{$order->id}/pay")->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_already_paid_order_gets_422(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order has already been paid.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_cancelled_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->cancelled()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order can no longer be paid.');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_paying_a_cod_order_twice_keeps_a_single_payment_row(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    // ------------------------------------------------------------------
    // POST /api/orders/{order}/confirm-cash-payment  (admin)
    // ------------------------------------------------------------------

    public function test_confirm_cash_is_admin_only(): void
    {
        $order = Order::factory()->create();
        $payment = $this->codPayment($order);
        $url = "/api/orders/{$order->id}/confirm-cash-payment";

        $this->postJson($url)->assertUnauthorized();

        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer, 'sanctum')->postJson($url)->assertForbidden();

        $seller = User::factory()->create(['role' => 'seller']);
        $this->actingAs($seller, 'sanctum')->postJson($url)->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_admin_can_confirm_cash_and_order_becomes_paid(): void
    {
        $order = Order::factory()->create();
        $payment = $this->codPayment($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-cash-payment")
            ->assertOk()
            ->assertJsonPath('message', 'Cash payment confirmed.');

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_confirm_cash_without_a_cod_payment_gets_404(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-cash-payment")
            ->assertNotFound();

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_confirm_cash_ignores_payments_from_other_gateways(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe']);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway'  => 'stripe',
            'amount'   => $order->total,
            'status'   => 'pending',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-cash-payment")
            ->assertNotFound();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_confirming_cash_twice_gets_422(): void
    {
        $order = Order::factory()->create();
        $this->codPayment($order);
        $admin = $this->admin();
        $url = "/api/orders/{$order->id}/confirm-cash-payment";

        $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This payment has already been confirmed.');
    }

    public function test_cash_cannot_be_confirmed_for_a_cancelled_order(): void
    {
        $order = Order::factory()->cancelled()->create();
        $payment = $this->codPayment($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-cash-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order was cancelled.');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_confirm_cash_for_an_unknown_order_gets_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/orders/999999/confirm-cash-payment')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // PaymentService::recordPaid  (what every gateway webhook ends up calling)
    // ------------------------------------------------------------------

    public function test_record_paid_creates_a_paid_payment_and_marks_the_order_paid(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe']);

        app(PaymentService::class)->recordPaid($order, 'stripe', 'pi_123');

        $this->assertDatabaseHas('payments', [
            'order_id'               => $order->id,
            'gateway'                => 'stripe',
            'gateway_transaction_id' => 'pi_123',
            'status'                 => 'paid',
        ]);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_record_paid_is_idempotent_for_retried_webhooks(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe']);
        $service = app(PaymentService::class);

        $service->recordPaid($order, 'stripe', 'pi_123');
        $service->recordPaid($order, 'stripe', 'pi_123');
        $service->recordPaid($order, 'stripe', 'pi_other');

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame('pi_123', Payment::where('order_id', $order->id)->value('gateway_transaction_id'));
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_payment_for_a_cancelled_order_is_recorded_but_does_not_revive_it(): void
    {
        Log::spy();

        $order = Order::factory()->cancelled()->create();

        // 'cod' is used as the gateway so the automatic refund fails locally
        // (cash cannot be refunded through the system) without any network call.
        app(PaymentService::class)->recordPaid($order, 'cod', 'TX-LATE');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway'  => 'cod',
            'status'   => 'paid',
        ]);

        // The failed refund must be logged loudly so someone refunds manually.
        Log::shouldHaveReceived('critical')->once();
    }
}
