<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Concerns\AuthorizesOrderOwnership;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesOrderOwnership;

    public function __construct(private PaymentService $paymentService) {}

  public function pay(Request $request, Order $order)
{
    $this->authorizeOrderOwnership($request, $order);

    if ($order->status === 'paid') {
        return response()->json(['message' => 'This order has already been paid.'], 422);
    }

    $validated = $request->validate([
        'saved_payment_method_id' => ['nullable', 'string'],
    ]);

    $result = $this->paymentService->pay($order, $validated['saved_payment_method_id'] ?? null);

    return response()->json([
        'success'      => $result->success,
        'status'       => $result->status,
        'redirect_url' => $result->redirectUrl,
        'gateway_data' => $result->gatewayData ?? null,
        'message'      => $result->message,
    ]);
}
}