<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\PaymentService;

class RazorpayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

public function charge(Order $order, ?string $savedPaymentMethodId = null): PaymentResult
    {
        $amountInPaise = (int) round(
            $order->total * config('services.razorpay.egp_to_inr_rate') * 100
        );

        $response = Http::withBasicAuth(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        )->post(self::BASE_URL . '/orders', [
            'amount'   => $amountInPaise,
            'currency' => 'INR',
            'receipt'  => $order->order_number,
            'notes'    => [
                'order_id' => (string) $order->id,
            ],
        ]);

        if ($response->failed()) {
            Log::error('Razorpay order creation failed.', ['order_id' => $order->id, 'body' => $response->body()]);

            return new PaymentResult(
                success: false,
                status: 'failed',
                message: 'Unable to create Razorpay order.',
            );
        }

        $razorpayOrder = $response->json();

        return new PaymentResult(
                success: true,
                status: 'pending',
                transactionId: $razorpayOrder['id'],
                gatewayData: [
                'razorpay_key_id'   => config('services.razorpay.key_id'),
                'razorpay_order_id' => $razorpayOrder['id'],
                'amount'            => $amountInPaise,
                'currency'          => 'INR',
    ],
);
    }
    public function refund(Payment $payment): PaymentResult
{
    $response = Http::withBasicAuth(
        config('services.razorpay.key_id'),
        config('services.razorpay.key_secret')
    )->post(self::BASE_URL . "/payments/{$payment->gateway_transaction_id}/refund");

    if ($response->failed()) {
        Log::error('Razorpay refund failed.', ['payment_id' => $payment->id, 'body' => $response->body()]);

        return new PaymentResult(
            success: false,
            status: 'failed',
            transactionId: $payment->gateway_transaction_id,
            message: 'Razorpay refund request failed.',
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
        $payload   = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');

        if (! $this->verifySignature($payload, $signature)) {
            Log::warning('Razorpay webhook signature verification failed.');
            abort(400, 'Invalid webhook signature.');
        }

        $event = json_decode($payload, true);

        if (($event['event'] ?? null) !== 'payment.captured') {
            return;
        }

        $paymentEntity = $event['payload']['payment']['entity'] ?? null;

        if (! $paymentEntity) {
            Log::warning('Razorpay webhook missing payment entity.', ['event' => $event]);
            return;
        }

        $order = Payment::where('gateway', 'razorpay')
            ->whereIn('gateway_transaction_id', array_filter([
                $paymentEntity['order_id'] ?? null,
                $paymentEntity['id'] ?? null,
            ]))
            ->first()?->order;

        if (! $order && ! empty($paymentEntity['notes']['order_id'])) {
            $order = Order::find($paymentEntity['notes']['order_id']);
        }

        if (! $order) {
            Log::warning('Razorpay webhook could not be matched to an order.', ['payment_id' => $paymentEntity['id'] ?? null]);
            return;
        }

        app(PaymentService::class)->recordPaid($order, 'razorpay', $paymentEntity['id']);
    }

private function verifySignature(string $payload, ?string $signature): bool
{
    $secret = config('services.razorpay.webhook_secret');

    // A missing secret would let anyone sign a payload with an empty key.
    if (! is_string($secret) || $secret === '') {
        Log::critical('Razorpay webhook secret is not configured; rejecting webhook.');

        return false;
    }

    if (! $signature) {
        return false;
    }

    $expected = hash_hmac('sha256', $payload, $secret);

    return hash_equals($expected, $signature);
}
}