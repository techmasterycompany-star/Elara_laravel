<?php

namespace App\Services;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\PaymentResult;
use Illuminate\Support\Facades\DB;
use App\Services\Payments\Gateways;
use Illuminate\Http\Request;

class PaymentService
{
    public function pay(Order $order): PaymentResult
    {
        // ---- Idempotency المركزية: اتأكد إنه مدفوع بالفعل قبل أي حاجة تانية ----
        $existing = Payment::where('order_id', $order->id)
            ->where('status', 'paid')
            ->first();

        if ($existing) {
            return new PaymentResult(
                success: true,
                status: 'paid',
                transactionId: $existing->gateway_transaction_id,
                message: 'This order has already been paid.',
            );
        }

        $gateway = $this->resolveGateway($order->payment_method);

        $result = $gateway->charge($order);

        DB::transaction(function () use ($order, $result) {
            Payment::updateOrCreate(
                ['order_id' => $order->id, 'gateway' => $order->payment_method],
                [
                    'gateway_transaction_id' => $result->transactionId,
                    'amount'                 => $order->total,
                    'status'                 => $result->status,
                ]
            );

            if ($result->status === 'paid') {
                $order->update(['status' => 'paid']);
            }
        });

        return $result;
    }

    // app/Services/PaymentService.php


private function resolveGateway(string $method): PaymentGateway
{
    return match ($method) {
        'cod'    => app(\App\Services\Payments\Gateways\CashOnDeliveryGateway::class),
        'stripe' => app(\App\Support\Payments\StripeGateway::class),
        'wallet' => app(\App\Support\Payments\WalletGateway::class),
        'paypal' => app(\App\Support\Payments\PayPalGateway::class),   
        default  => throw new \RuntimeException("Payment gateway [{$method}] is not implemented yet."),
    };
}
    public function confirmCashPayment(Request $request, Order $order)
{
    $payment = $order->payment()->where('gateway', 'cod')->first();

    if (! $payment) {
        return response()->json([
            'message' => 'No cash-on-delivery payment found for this order.',
        ], 404);
    }

    if ($payment->status === 'paid') {
        return response()->json([
            'message' => 'This payment has already been confirmed.',
        ], 422);
    }

    DB::transaction(function () use ($payment, $order) {
        $payment->update(['status' => 'paid']);
        $order->update(['status' => 'paid']);
    });

    return response()->json([
        'message' => 'Cash payment confirmed.',
    ]);
}
public function refund(Order $order): PaymentResult
{
    $payment = Payment::where('order_id', $order->id)
        ->where('status', 'paid')
        ->first();

    if (! $payment) {
        return new PaymentResult(
            success: false,
            status: 'failed',
            transactionId: null,
            redirectUrl: null,
            message: 'No paid payment found to refund for this order.',
        );
    }

    $gateway = $this->resolveGateway($payment->gateway);

    return $gateway->refund($payment);
}
}