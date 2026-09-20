<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RazorpayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function charge(Order $order): PaymentResult
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
        return; // مهتمين بالحدث ده بس دلوقتي
    }

    $paymentEntity = $event['payload']['payment']['entity'] ?? null;
    $orderId       = $paymentEntity['notes']['order_id'] ?? null;

    if (! $paymentEntity || ! $orderId) {
        Log::warning('Razorpay webhook missing order identifiers.', ['event' => $event]);
        return;
    }

    $order = Order::find($orderId);

    if (! $order) {
        Log::warning('Razorpay webhook references a non-existent order.', ['order_id' => $orderId]);
        return;
    }

    if (Payment::where('order_id', $order->id)->where('status', 'paid')->exists()) {
        return;
    }

    DB::transaction(function () use ($order, $paymentEntity) {
        Payment::updateOrCreate(
            ['order_id' => $order->id, 'gateway' => 'razorpay'],
            [
                'gateway_transaction_id' => $paymentEntity['id'],
                'amount'                 => $order->total,
                'status'                 => 'paid',
            ]
        );

        $order->update(['status' => 'paid']);
    });
}

private function verifySignature(string $payload, ?string $signature): bool
{
    if (! $signature) {
        return false;
    }

    $expected = hash_hmac('sha256', $payload, config('services.razorpay.webhook_secret'));

    return hash_equals($expected, $signature);
}
}