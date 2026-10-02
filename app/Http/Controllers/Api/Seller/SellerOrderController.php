<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class SellerOrderController extends Controller
{
    // ---- #38 View order items containing the seller's own products ----
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,processing,shipped,delivered,cancelled'],
        ]);

        $query = OrderItem::where('seller_id', $seller->id)
            ->with(['order:id,order_number,status,created_at,shipping_name,shipping_phone,shipping_city', 'product:id,name,sku']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $items = $query->latest()->paginate(20);

        return response()->json($items);
    }

    // ---- #38 View a single order item belonging to the seller ----
    public function show(Request $request, OrderItem $item)
    {
        $seller = $request->user()->seller;

        if (! $seller || $item->seller_id !== $seller->id) {
            abort(403, 'You do not have permission to view this order item.');
        }

        $item->load(['order', 'product:id,name,sku']);

        return response()->json([
            'item' => $item,
        ]);
    }
}