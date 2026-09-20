<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PayPalGateway implements PaymentGateway
{
    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function getAccessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post($this->baseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            Log::error('PayPal OAuth token request failed.', ['body' => $response->body()]);
            throw new \RuntimeException('Unable to authenticate with PayPal.');
        }

        return $response->json('access_token');
    }

public function charge(Order $order, ?string $savedPaymentMethodId = null): PaymentResult
{
    $accessToken = $this->getAccessToken();

    $response = Http::withToken($accessToken)
        ->post($this->baseUrl() . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $order->id,
                'custom_id'    => (string) $order->id,
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($order->total * config('services.paypal.egp_to_usd_rate'), 2, '.', ''),
                ],
                'description' => "GlowThera Order #{$order->order_number}",
            ]],
            'application_context' => [
                'return_url' => config('app.frontend_url') . '/orders/' . $order->id . '?payment=success',
                'cancel_url' => config('app.frontend_url') . '/orders/' . $order->id . '?payment=cancelled',
            ],
        ]);

    if ($response->failed()) {
        Log::error('PayPal order creation failed.', ['order_id' => $order->id, 'body' => $response->body()]);

        return new PaymentResult(
            success: false,
            status: 'failed',
            message: 'Unable to create PayPal order.',
        );
    }

    $paypalOrder = $response->json();
    $approveLink = collect($paypalOrder['links'])->firstWhere('rel', 'approve')['href'] ?? null;

    return new PaymentResult(
        success: true,
        status: 'pending',
        transactionId: $paypalOrder['id'],
        redirectUrl: $approveLink,
    );
}
public function refund(Payment $payment): PaymentResult
{
    $accessToken = $this->getAccessToken();

    $response = Http::withToken($accessToken)
        ->post($this->baseUrl() . "/v2/payments/captures/{$payment->gateway_transaction_id}/refund");

    if ($response->failed()) {
        Log::error('PayPal refund failed.', ['payment_id' => $payment->id, 'body' => $response->body()]);

        return new PaymentResult(
            success: false,
            status: 'failed',
            transactionId: $payment->gateway_transaction_id,
            message: 'PayPal refund request failed.',
        );
    }

    $payment->update(['status' => 'refunded']);

    return new PaymentResult(
        success: true,
        status: 'refunded',
        transactionId: $response->json('id'),
    );
}
public function handleWebhook(Request $request): void
{
    $payload = $request->getContent();
    $event = json_decode($payload, true);

    if (! $this->verifyWebhookSignature($request, $payload)) {
        Log::warning('PayPal webhook signature verification failed.', ['event_type' => $event['event_type'] ?? null]);
        abort(400, 'Invalid webhook signature.');
    }

    if (($event['event_type'] ?? null) !== 'CHECKOUT.ORDER.APPROVED') {
        return; 
    }

    $paypalOrderId = $event['resource']['id'] ?? null;
    $orderId       = $event['resource']['purchase_units'][0]['reference_id'] ?? null;

    if (! $paypalOrderId || ! $orderId) {
        Log::warning('PayPal webhook missing order identifiers.', ['event' => $event]);
        return;
    }

    $order = Order::find($orderId);

    if (! $order) {
        Log::warning('PayPal webhook references a non-existent order.', ['order_id' => $orderId]);
        return;
    }

    if (Payment::where('order_id', $order->id)->where('status', 'paid')->exists()) {
        return;
    }

    $this->captureAndRecord($order, $paypalOrderId);
}

private function captureAndRecord(Order $order, string $paypalOrderId): void
{
    $accessToken = $this->getAccessToken();

    $response = Http::withToken($accessToken)
        ->post($this->baseUrl() . "/v2/checkout/orders/{$paypalOrderId}/capture");

    if ($response->failed()) {
        Log::error('PayPal capture failed.', ['order_id' => $order->id, 'body' => $response->body()]);
        return;
    }

    $captureId = $response->json('purchase_units.0.payments.captures.0.id') ?? $paypalOrderId;

    DB::transaction(function () use ($order, $captureId) {
        Payment::updateOrCreate(
            ['order_id' => $order->id, 'gateway' => 'paypal'],
            [
                'gateway_transaction_id' => $captureId,
                'amount'                 => $order->total,
                'status'                 => 'paid',
            ]
        );

        $order->update(['status' => 'paid']);
    });
}

private function verifyWebhookSignature(Request $request, string $payload): bool
{
    $accessToken = $this->getAccessToken();

    $response = Http::withToken($accessToken)
        ->post($this->baseUrl() . '/v1/notifications/verify-webhook-signature', [
            'transmission_id'   => $request->header('paypal-transmission-id'),
            'transmission_time' => $request->header('paypal-transmission-time'),
            'cert_url'          => $request->header('paypal-cert-url'),
            'auth_algo'         => $request->header('paypal-auth-algo'),
            'transmission_sig'  => $request->header('paypal-transmission-sig'),
            'webhook_id'        => config('services.paypal.webhook_id'),
            'webhook_event'     => json_decode($payload, true),
        ]);

    return $response->successful() && $response->json('verification_status') === 'SUCCESS';
}
}