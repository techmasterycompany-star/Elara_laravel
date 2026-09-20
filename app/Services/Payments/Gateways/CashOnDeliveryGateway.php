<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\PaymentResult;
use Illuminate\Http\Request;

class CashOnDeliveryGateway implements PaymentGateway
{
    public function charge(Order $order, ?string $savedPaymentMethodId = null): PaymentResult
    {
        // مفيش أي API خارجي — بس بنسجل إن الأوردر ده هيتدفع كاش وقت التسليم
        return new PaymentResult(
            success: true,
            status: 'pending',
            transactionId: null,
            message: 'Payment will be collected on delivery.',
        );
    }

  public function refund(Payment $payment): PaymentResult
{
    return new PaymentResult(
        success: false,
        status: 'failed',
        transactionId: $payment->gateway_transaction_id,   
        redirectUrl: null,
        message: 'Cash on Delivery payments must be refunded manually by an admin — the money was collected in cash, not through the system.',
    );
}

    public function handleWebhook(Request $request): void
    {
        throw new \RuntimeException('Cash on delivery does not support webhooks.');
    }
}