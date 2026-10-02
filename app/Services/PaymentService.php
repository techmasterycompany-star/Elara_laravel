<?php

namespace App\Services;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function pay(Order $order, ?string $savedPaymentMethodId = null): PaymentResult
    {
        $payment = Payment::where('order_id', $order->id)
            ->where('status', 'paid')
            ->first();

        if ($payment) {
            return new PaymentResult(
                success: true,
                status: 'paid',
                transactionId: $payment->gateway_transaction_id,
                redirectUrl: null,
                message: 'Order already paid.',
            );
        }

        $gateway = $this->resolveGateway($order->payment_method);
        $result  = $gateway->charge($order, $savedPaymentMethodId);

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

    /**
     * The single place where a gateway webhook confirmation is recorded.
     * - Idempotent: a retried webhook for an already-paid order is a no-op.
     * - Serialised per order with a row lock, so concurrent deliveries can't double-record.
     * - If the order was cancelled while the customer was paying, the payment is
     *   recorded and refunded instead of resurrecting a cancelled order.
     */
    public function recordPaid(Order $order, string $gateway, string $transactionId): void
    {
        $refundNeeded = DB::transaction(function () use ($order, $gateway, $transactionId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (Payment::where('order_id', $locked->id)->where('status', 'paid')->exists()) {
                return false;
            }

            Payment::updateOrCreate(
                ['order_id' => $locked->id, 'gateway' => $gateway],
                [
                    'gateway_transaction_id' => $transactionId,
                    'amount'                 => $locked->total,
                    'status'                 => 'paid',
                ]
            );

            if ($locked->status === 'cancelled') {
                return true;
            }

            $locked->update(['status' => 'paid']);

            return false;
        });

        if ($refundNeeded) {
            $result = $this->refund($order);

            if (! $result->success) {
                Log::critical('Payment received for a cancelled order and the automatic refund failed. Refund manually.', [
                    'order_id'       => $order->id,
                    'gateway'        => $gateway,
                    'transaction_id' => $transactionId,
                    'message'        => $result->message,
                ]);
            }
        }
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

    private function resolveGateway(string $method): PaymentGateway
    {
        return match ($method) {
            'cod'      => app(\App\Services\Payments\Gateways\CashOnDeliveryGateway::class),
            'stripe'   => app(\App\Support\Payments\StripeGateway::class),
            'wallet'   => app(\App\Support\Payments\WalletGateway::class),
            'paypal'   => app(\App\Support\Payments\PayPalGateway::class),
            'razorpay' => app(\App\Support\Payments\RazorpayGateway::class),
            default    => throw new \RuntimeException("Payment gateway [{$method}] is not implemented yet."),
        };
    }
}