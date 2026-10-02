<?php

namespace App\Contracts\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\PaymentResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function charge(Order $order, ?string $savedPaymentMethodId = null): PaymentResult;
    public function refund(Payment $payment): PaymentResult;

    public function handleWebhook(Request $request): void;
}