<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\CardException;
use Stripe\Exception\ApiErrorException;
use UnexpectedValueException;

class StripeGateway implements PaymentGateway
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * لو مفيش بطاقة محفوظة متبعتة: نفس تدفق الـ Checkout القديم (redirect لصفحة Stripe).
     * لو فيه بطاقة محفوظة (savedPaymentMethodId): دفع فوري من غير أي redirect.
     */
    public function charge(Order $order, ?string $savedPaymentMethodId = null): PaymentResult
    {
        if (! $savedPaymentMethodId) {
            return $this->chargeViaCheckoutSession($order);
        }

        return $this->chargeViaSavedCard($order, $savedPaymentMethodId);
    }

    private function chargeViaCheckoutSession(Order $order): PaymentResult
    {
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency'     => 'egp',
                    'unit_amount'  => (int) round($order->total * 100),
                    'product_data' => [
                        'name' => "GlowThera Order #{$order->order_number}",
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'order_id' => $order->id,
            ],
            'success_url' => config('app.frontend_url') . '/orders/' . $order->id . '?payment=success',
            'cancel_url'  => config('app.frontend_url') . '/orders/' . $order->id . '?payment=cancelled',
        ]);

        return new PaymentResult(
            success: true,
            status: 'pending',
            transactionId: $session->id,
            redirectUrl: $session->url,
            message: null,
        );
    }

    private function chargeViaSavedCard(Order $order, string $savedPaymentMethodId): PaymentResult
    {
        if (! $order->user_id) {
            return new PaymentResult(
                success: false,
                status: 'failed',
                transactionId: null,
                redirectUrl: null,
                message: 'Saved cards require a logged-in account, not available for guest checkout.',
            );
        }

        $user = $order->user;

        $ownsPaymentMethod = PaymentMethod::where('user_id', $user->id)
            ->where('gateway', 'stripe')
            ->where('token', $savedPaymentMethodId)
            ->exists();

        if (! $ownsPaymentMethod) {
            return new PaymentResult(
                success: false,
                status: 'failed',
                transactionId: null,
                redirectUrl: null,
                message: 'This saved card does not belong to you.',
            );
        }

        try {
            $paymentIntent = $this->client->paymentIntents->create([
                'amount'         => (int) round($order->total * 100),
                'currency'       => 'egp',
                'customer'       => $user->stripe_customer_id,
                'payment_method' => $savedPaymentMethodId,
                'off_session'    => true,
                'confirm'        => true,
                'metadata'       => [
                    'order_id' => $order->id,
                ],
            ]);
        } catch (CardException $e) {
            return new PaymentResult(
                success: false,
                status: 'failed',
                transactionId: null,
                redirectUrl: null,
                message: $e->getMessage(),
            );
        }

        return new PaymentResult(
            success: $paymentIntent->status === 'succeeded',
            status: $paymentIntent->status === 'succeeded' ? 'paid' : 'failed',
            transactionId: $paymentIntent->id,
            redirectUrl: null,
            message: null,
        );
    }

    public function refund(Payment $payment): PaymentResult
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $payment->gateway_transaction_id,
            ]);
        } catch (ApiErrorException $e) {
            return new PaymentResult(
                success: false,
                status: 'failed',
                transactionId: $payment->gateway_transaction_id,
                redirectUrl: null,
                message: $e->getMessage(),
            );
        }

        $payment->update(['status' => 'refunded']);

        return new PaymentResult(
            success: true,
            status: 'refunded',
            transactionId: $refund->id,
            redirectUrl: null,
            message: null,
        );
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            abort(400, 'Invalid webhook signature.');
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $orderId = $session->metadata->order_id ?? null;

            if (! $orderId) {
                Log::warning('Stripe webhook missing order_id in metadata.', ['session_id' => $session->id]);
                return;
            }

            $order = Order::find($orderId);

            if (! $order) {
                Log::warning('Stripe webhook references a non-existent order.', ['order_id' => $orderId]);
                return;
            }

            $existingPayment = Payment::where('order_id', $order->id)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                return;
            }

            DB::transaction(function () use ($order, $session) {
                Payment::updateOrCreate(
                    ['order_id' => $order->id, 'gateway' => 'stripe'],
                    [
                        'gateway_transaction_id' => $session->payment_intent ?? $session->id,
                        'amount'                 => $order->total,
                        'status'                 => 'paid',
                    ]
                );

                $order->update(['status' => 'paid']);
            });
        }

        if ($event->type === 'setup_intent.succeeded') {
            $setupIntent = $event->data->object;
            $customerId = $setupIntent->customer;
            $paymentMethodId = $setupIntent->payment_method;

            if (! $customerId || ! $paymentMethodId) {
                Log::warning('Stripe setup_intent.succeeded missing customer or payment_method.', [
                    'setup_intent_id' => $setupIntent->id,
                ]);
                return;
            }

            $user = User::where('stripe_customer_id', $customerId)->first();

            if (! $user) {
                Log::warning('Stripe setup_intent.succeeded references an unknown customer.', [
                    'customer_id' => $customerId,
                ]);
                return;
            }

            $alreadySaved = PaymentMethod::where('user_id', $user->id)
                ->where('gateway', 'stripe')
                ->where('token', $paymentMethodId)
                ->exists();

            if ($alreadySaved) {
                return;
            }

            $paymentMethodDetails = $this->client->paymentMethods->retrieve($paymentMethodId);

            $isFirstMethod = $user->paymentMethods()->doesntExist();

            $user->paymentMethods()->create([
                'gateway'             => 'stripe',
                'gateway_customer_id' => $customerId,
                'token'               => $paymentMethodId,
                'card_brand'          => $paymentMethodDetails->card->brand ?? null,
                'card_last_four'      => $paymentMethodDetails->card->last4 ?? null,
                'is_default'          => $isFirstMethod,
            ]);
        }
    }

    private function getOrCreateCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client->customers->create([
            'name'  => $user->name,
            'email' => $user->email,
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    public function createSetupIntent(User $user): array
    {
        $customerId = $this->getOrCreateCustomer($user);

        $setupIntent = $this->client->setupIntents->create([
            'customer'             => $customerId,
            'payment_method_types' => ['card'],
        ]);

        return [
            'client_secret' => $setupIntent->client_secret,
        ];
    }
}