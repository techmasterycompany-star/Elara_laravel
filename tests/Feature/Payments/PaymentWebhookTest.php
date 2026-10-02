<?php
// tests/Feature/Payments/PaymentWebhookTest.php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET   = 'whsec_test_secret';
    private const RAZORPAY_SECRET = 'rzp_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret'           => 'sk_test_dummy',
            'services.stripe.webhook_secret'   => self::STRIPE_SECRET,
            'services.razorpay.webhook_secret' => self::RAZORPAY_SECRET,
            'services.razorpay.key_id'         => 'rzp_key',
            'services.razorpay.key_secret'     => 'rzp_key_secret',
            'services.paypal.client_id'        => 'pp_client',
            'services.paypal.secret'           => 'pp_secret',
            'services.paypal.webhook_id'       => 'WH-TEST',
            'services.paypal.mode'             => 'sandbox',
        ]);
    }

    private function order(string $method, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => $method,
            'status'         => 'pending_payment',
        ], $attributes));
    }

    // ==================================================================
    // Stripe
    // ==================================================================

    private function stripeEvent(string $type, array $object): array
    {
        return [
            'id'     => 'evt_test_1',
            'object' => 'event',
            'type'   => $type,
            'data'   => ['object' => $object],
        ];
    }

    private function stripeCheckoutCompleted(Order $order, array $overrides = []): array
    {
        return $this->stripeEvent('checkout.session.completed', array_merge([
            'id'             => 'cs_test_1',
            'object'         => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_1',
            'metadata'       => ['order_id' => (string) $order->id],
        ], $overrides));
    }

    private function postStripe(array $event, ?string $secret = self::STRIPE_SECRET, bool $signed = true)
    {
        $payload = json_encode($event);
        $server  = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signed) {
            $timestamp = time();
            $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) $secret);
            $server['HTTP_STRIPE_SIGNATURE'] = "t={$timestamp},v1={$signature}";
        }

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], $server, $payload);
    }

    public function test_stripe_webhook_with_a_bad_signature_is_rejected(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeCheckoutCompleted($order), 'wrong_secret')->assertStatus(400);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stripe_webhook_without_a_signature_header_is_rejected(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeCheckoutCompleted($order), signed: false)->assertStatus(400);

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_stripe_paid_checkout_marks_the_order_paid(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeCheckoutCompleted($order))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id'               => $order->id,
            'gateway'                => 'stripe',
            'gateway_transaction_id' => 'pi_test_1',
            'status'                 => 'paid',
        ]);
    }

    public function test_stripe_unpaid_checkout_does_not_mark_the_order_paid(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeCheckoutCompleted($order, ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stripe_event_without_order_id_or_with_unknown_order_is_ignored(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeCheckoutCompleted($order, ['metadata' => []]))->assertOk();
        $this->postStripe($this->stripeCheckoutCompleted($order, ['metadata' => ['order_id' => '999999']]))->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stripe_retried_webhook_records_a_single_payment(): void
    {
        $order = $this->order('stripe');
        $event = $this->stripeCheckoutCompleted($order);

        $this->postStripe($event)->assertOk();
        $this->postStripe($event)->assertOk();

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_stripe_unrelated_event_types_are_acknowledged_and_ignored(): void
    {
        $order = $this->order('stripe');

        $this->postStripe($this->stripeEvent('charge.succeeded', ['id' => 'ch_1', 'object' => 'charge']))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    // ==================================================================
    // Razorpay
    // ==================================================================

    private function razorpayEvent(array $entity, string $event = 'payment.captured'): array
    {
        return ['event' => $event, 'payload' => ['payment' => ['entity' => $entity]]];
    }

    private function postRazorpay(array $event, ?string $secret = self::RAZORPAY_SECRET, bool $signed = true)
    {
        $payload = json_encode($event);
        $server  = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signed) {
            $server['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $payload, (string) $secret);
        }

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], $server, $payload);
    }

    private function razorpayPendingPayment(Order $order, string $razorpayOrderId = 'order_RZ1'): Payment
    {
        return Payment::create([
            'order_id'               => $order->id,
            'gateway'                => 'razorpay',
            'gateway_transaction_id' => $razorpayOrderId,
            'amount'                 => $order->total,
            'status'                 => 'pending',
        ]);
    }

    public function test_razorpay_webhook_with_a_bad_or_missing_signature_is_rejected(): void
    {
        $order = $this->order('razorpay');
        $this->razorpayPendingPayment($order);
        $event = $this->razorpayEvent(['id' => 'pay_1', 'order_id' => 'order_RZ1']);

        $this->postRazorpay($event, 'wrong_secret')->assertStatus(400);
        $this->postRazorpay($event, signed: false)->assertStatus(400);

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_razorpay_captured_payment_marks_the_order_paid(): void
    {
        $order = $this->order('razorpay');
        $payment = $this->razorpayPendingPayment($order);

        $this->postRazorpay($this->razorpayEvent(['id' => 'pay_1', 'order_id' => 'order_RZ1']))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pay_1', $payment->fresh()->gateway_transaction_id);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_razorpay_falls_back_to_the_order_id_in_notes(): void
    {
        $order = $this->order('razorpay');

        $this->postRazorpay($this->razorpayEvent([
            'id'       => 'pay_2',
            'order_id' => 'order_UNKNOWN',
            'notes'    => ['order_id' => (string) $order->id],
        ]))->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id'               => $order->id,
            'gateway'                => 'razorpay',
            'gateway_transaction_id' => 'pay_2',
            'status'                 => 'paid',
        ]);
    }

    public function test_razorpay_unmatched_payment_and_other_events_are_ignored(): void
    {
        $order = $this->order('razorpay');
        $this->razorpayPendingPayment($order);

        $this->postRazorpay($this->razorpayEvent(['id' => 'pay_9', 'order_id' => 'order_NOPE']))->assertOk();
        $this->postRazorpay($this->razorpayEvent(['id' => 'pay_1', 'order_id' => 'order_RZ1'], 'payment.failed'))->assertOk();
        $this->postRazorpay(['event' => 'payment.captured', 'payload' => []])->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_razorpay_retried_webhook_records_a_single_payment(): void
    {
        $order = $this->order('razorpay');
        $this->razorpayPendingPayment($order);
        $event = $this->razorpayEvent(['id' => 'pay_1', 'order_id' => 'order_RZ1']);

        $this->postRazorpay($event)->assertOk();
        $this->postRazorpay($event)->assertOk();

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    // ==================================================================
    // PayPal  (all HTTP calls to PayPal are faked)
    // ==================================================================

    private function paypalEvent(Order $order, string $type = 'CHECKOUT.ORDER.APPROVED', string $paypalOrderId = 'PP-1'): array
    {
        return [
            'event_type' => $type,
            'resource'   => [
                'id'             => $paypalOrderId,
                'purchase_units' => [['reference_id' => (string) $order->id]],
            ],
        ];
    }

    private function fakePaypal(string $verification = 'SUCCESS', array $extra = []): void
    {
        Http::fake(array_merge([
            '*/v1/oauth2/token'                          => Http::response(['access_token' => 'tok']),
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verification]),
        ], $extra));
    }

    private function completedCapture(string $captureId = 'CAP-1'): array
    {
        return [
            'status'         => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => $captureId]]]]],
        ];
    }

    private function postPaypal(array $event)
    {
        return $this->postJson('/api/webhooks/paypal', $event, [
            'paypal-transmission-id'   => 'tx-1',
            'paypal-transmission-time' => now()->toIso8601String(),
            'paypal-cert-url'          => 'https://api.sandbox.paypal.com/cert',
            'paypal-auth-algo'         => 'SHA256withRSA',
            'paypal-transmission-sig'  => 'sig',
        ]);
    }

    private function assertPaypalCaptured(bool $expected): void
    {
        $sent = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/capture'))->isNotEmpty();

        $this->assertSame($expected, $sent, $expected ? 'PayPal capture was not requested.' : 'PayPal capture was requested unexpectedly.');
    }

    public function test_paypal_webhook_that_fails_verification_is_rejected_without_capturing(): void
    {
        $this->fakePaypal('FAILURE');
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order))->assertStatus(400);

        $this->assertPaypalCaptured(false);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paypal_approved_order_is_captured_and_marked_paid(): void
    {
        $this->fakePaypal(extra: ['*/v2/checkout/orders/PP-1/capture' => Http::response($this->completedCapture('CAP-1'))]);
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertPaypalCaptured(true);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id'               => $order->id,
            'gateway'                => 'paypal',
            'gateway_transaction_id' => 'CAP-1',
            'status'                 => 'paid',
        ]);
    }

    public function test_paypal_other_event_types_do_not_capture(): void
    {
        $this->fakePaypal();
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order, 'PAYMENT.CAPTURE.COMPLETED'))->assertOk();

        $this->assertPaypalCaptured(false);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_paypal_event_for_an_unknown_order_does_not_capture(): void
    {
        $this->fakePaypal();
        $order = $this->order('paypal');
        $event = $this->paypalEvent($order);
        $event['resource']['purchase_units'][0]['reference_id'] = '999999';

        $this->postPaypal($event)->assertOk();

        $this->assertPaypalCaptured(false);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_paypal_event_for_an_already_paid_order_does_not_capture_again(): void
    {
        $this->fakePaypal();
        $order = $this->order('paypal', ['status' => 'paid']);
        Payment::create([
            'order_id'               => $order->id,
            'gateway'                => 'paypal',
            'gateway_transaction_id' => 'CAP-OLD',
            'amount'                 => $order->total,
            'status'                 => 'paid',
        ]);

        $this->postPaypal($this->paypalEvent($order))->assertOk();

        $this->assertPaypalCaptured(false);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_paypal_capture_that_is_not_completed_leaves_the_order_unpaid(): void
    {
        $this->fakePaypal(extra: ['*/v2/checkout/orders/PP-1/capture' => Http::response(['status' => 'PENDING'])]);
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order))->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paypal_capture_failure_returns_500_so_paypal_retries(): void
    {
        $this->fakePaypal(extra: ['*/v2/checkout/orders/PP-1/capture' => Http::response(['message' => 'boom'], 500)]);
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order))->assertStatus(500);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paypal_already_captured_order_is_recovered_by_reading_it_back(): void
    {
        $this->fakePaypal(extra: [
            '*/v2/checkout/orders/PP-1/capture' => Http::response(['details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            '*/v2/checkout/orders/PP-1'         => Http::response($this->completedCapture('CAP-7')),
        ]);
        $order = $this->order('paypal');

        $this->postPaypal($this->paypalEvent($order))->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id'               => $order->id,
            'gateway_transaction_id' => 'CAP-7',
            'status'                 => 'paid',
        ]);
    }

    public function test_paypal_payment_for_a_cancelled_order_is_refunded_and_the_order_stays_cancelled(): void
    {
        $this->fakePaypal(extra: [
            '*/v2/checkout/orders/PP-1/capture'     => Http::response($this->completedCapture('CAP-1')),
            '*/v2/payments/captures/CAP-1/refund'   => Http::response(['id' => 'REF-1']),
        ]);
        $order = $this->order('paypal', ['status' => 'cancelled']);

        $this->postPaypal($this->paypalEvent($order))->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('refunded', Payment::where('order_id', $order->id)->value('status'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/v2/payments/captures/CAP-1/refund'));
    }

    // ==================================================================
    // Hypothesis tests: webhook secret missing from the environment
    // ==================================================================

    public function test_razorpay_webhook_is_rejected_when_the_secret_is_not_configured(): void
    {
        config(['services.razorpay.webhook_secret' => null]);

        $order = $this->order('razorpay');
        $this->razorpayPendingPayment($order);

        // An attacker who knows the secret is empty can sign with an empty key.
        $response = $this->postRazorpay($this->razorpayEvent(['id' => 'pay_1', 'order_id' => 'order_RZ1']), '');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_stripe_webhook_is_rejected_when_the_secret_is_not_configured(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $order = $this->order('stripe');

        $response = $this->postStripe($this->stripeCheckoutCompleted($order), '');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame('pending_payment', $order->fresh()->status);
    }
}
