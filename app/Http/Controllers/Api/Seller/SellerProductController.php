<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SellerProductController extends Controller
{
    // ---- #37 Seller-scoped product listing (own products, any status) ----
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,active,hidden,rejected'],
        ]);

        $query = $seller->products()->with(['category', 'images']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $products = $query->latest()->paginate(20);

        return response()->json($products);
    }

    // ---- #37 Own product performance: stock alerts + basic counts ----
    public function performance(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $products = $seller->products()->get(['id', 'name', 'stock', 'status']);

        return response()->json([
            'total_products'    => $products->count(),
            'active_products'   => $products->where('status', 'active')->count(),
            'pending_products'  => $products->where('status', 'pending')->count(),
            'out_of_stock'      => $products->where('stock', 0)->count(),
            'low_stock'         => $products->where('stock', '>', 0)->where('stock', '<=', 5)->count(),
            'low_stock_products' => $products->where('stock', '>', 0)->where('stock', '<=', 5)
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'stock' => $p->stock])
                ->values(),
        ]);
    }
}