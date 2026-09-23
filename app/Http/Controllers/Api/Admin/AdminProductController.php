<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductController extends Controller
{
    
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status'      => ['nullable', 'in:pending,active,hidden,rejected'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'seller_id'   => ['nullable', 'exists:sellers,id'],
            'search'      => ['nullable', 'string', 'max:255'],
        ]);

        $query = Product::with(['category', 'seller', 'images']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (! empty($validated['seller_id'])) {
            $query->where('seller_id', $validated['seller_id']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%'.$validated['search'].'%');
        }

        $products = $query->latest()->paginate(20);

        return response()->json($products);
    }

    
    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'status'        => ['required', 'in:active,hidden,rejected'],
        ]);

        $updated = DB::transaction(function () use ($validated) {
            return Product::whereIn('id', $validated['product_ids'])
                ->update(['status' => $validated['status']]);
        });

        return response()->json([
            'message' => "{$updated} product(s) updated successfully.",
        ]);
    }
}