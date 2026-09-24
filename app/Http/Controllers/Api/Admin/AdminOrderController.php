<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status'    => ['nullable', 'in:pending_payment,paid,cancelled'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = Order::query()->with(['items', 'user:id,name,email']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator $orders */
        $orders = $query->latest()->paginate(20);

        $orders->through(function (Order $order) {
            $order->overall_status = $order->computedStatus();

            return $order;
        });

        return response()->json($orders);
    }

   
    public function show(Order $order)
    {
        $order->load(['items', 'user:id,name,email', 'payment']);

        return response()->json([
            'order'          => $order,
            'overall_status' => $order->computedStatus(),
        ]);
    }

   
    public function updateShipping(Request $request, Order $order)
    {
        $validated = $request->validate([
            'shipping_name'        => ['sometimes', 'string', 'max:255'],
            'shipping_phone'       => ['sometimes', 'string', 'max:50'],
            'shipping_street'      => ['sometimes', 'string', 'max:255'],
            'shipping_city'        => ['sometimes', 'string', 'max:100'],
            'shipping_governorate' => ['sometimes', 'string', 'max:100'],
        ]);

        $order->update($validated);

        return response()->json([
            'message' => 'Shipping information updated successfully.',
            'order'   => $order->fresh(),
        ]);
    }
}