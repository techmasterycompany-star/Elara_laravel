<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
 
    public function index(Request $request)
    {
        $items = $request->user()
            ->wishlist()
            ->with(['product' => function ($query) {
                $query->with(['category', 'images']);
            }])
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $product = Product::active()->find($validated['product_id']);

        if (! $product) {
            return response()->json([
                'message' => 'This product is not available.',
            ], 404);
        }

        $item = $request->user()->wishlist()->firstOrCreate([
            'product_id' => $product->id,
        ]);

        return response()->json([
            'message'  => $item->wasRecentlyCreated
                ? 'Product added to wishlist.'
                : 'Product is already in your wishlist.',
            'wishlist' => $item->load('product'),
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

   
    public function destroy(Request $request, Product $product)
    {
        $deleted = $request->user()
            ->wishlist()
            ->where('product_id', $product->id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'message' => 'This product is not in your wishlist.',
            ], 404);
        }

        return response()->json([
            'message' => 'Product removed from wishlist.',
        ]);
    }
}