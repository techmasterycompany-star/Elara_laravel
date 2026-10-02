<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Concerns\AuthorizesOrderOwnership;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    use AuthorizesOrderOwnership;

    public function __construct(private PaymentService $paymentService) {}

    public function pay(Request $request, Order $order)
    {
        $this->authorizeOrderOwnership($request, $order);

        $validated = $request->validate([
            'saved_payment_method_id' => ['nullable', 'string'],
        ]);

        $outcome = DB::transaction(function () use ($order, $validated) {
            // Lock the order so two concurrent /pay calls can't both charge it
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                return ['error' => 'This order has already been paid.'];
            }

            // Only orders still waiting for payment can be paid (blocks cancelled orders)
            if ($order->status !== 'pending_payment') {
                return ['error' => 'This order can no longer be paid.'];
            }

            return [
                'result' => $this->paymentService->pay(
                    $order,
                    $validated['saved_payment_method_id'] ?? null
                ),
            ];
        });

        if (isset($outcome['error'])) {
            return response()->json(['message' => $outcome['error']], 422);
        }

        $result = $outcome['result'];

        return response()->json([
            'success'      => $result->success,
            'status'       => $result->status,
            'redirect_url' => $result->redirectUrl,
            'gateway_data' => $result->gatewayData,
            'message'      => $result->message,
        ]);
    }

    /**
     * Admin confirms that cash was collected on delivery.
     * Route is already behind auth:sanctum + role:admin.
     */
    public function confirmCashPayment(Request $request, Order $order)
    {
        $outcome = DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled') {
                return 'cancelled';
            }

            $payment = $order->payment()->where('gateway', 'cod')->lockForUpdate()->first();

            if (! $payment) {
                return 'missing';
            }

            if ($payment->status === 'paid') {
                return 'already_paid';
            }

            $payment->update(['status' => 'paid']);
            $order->update(['status' => 'paid']);

            return 'confirmed';
        });

        return match ($outcome) {
            'missing'      => response()->json(['message' => 'No cash-on-delivery payment found for this order.'], 404),
            'cancelled'    => response()->json(['message' => 'This order was cancelled.'], 422),
            'already_paid' => response()->json(['message' => 'This payment has already been confirmed.'], 422),
            default        => response()->json(['message' => 'Cash payment confirmed.']),
        };
    }
}