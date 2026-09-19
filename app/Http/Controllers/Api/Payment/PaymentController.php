<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

   public function pay(Request $request, Order $order)
{
    $user = $request->user();

    if ($order->user_id && (! $user || $order->user_id !== $user->id)) {
        abort(403, 'This order does not belong to you.');
    }

    if ($order->status === 'paid') {
        return response()->json(['message' => 'This order has already been paid.'], 422);
    }

    $result = $this->paymentService->pay($order);

    return response()->json([
        'success'      => $result->success,
        'status'       => $result->status,
        'redirect_url' => $result->redirectUrl, 
        'message'      => $result->message,
    ]);
}
}