<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
   
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if ($product->reviews()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You have already reviewed this product.',
            ], 409);
        }

        if (! $this->isVerifiedPurchaser($user->id, $product->id)) {
            return response()->json([
                'message' => 'You can only review products you have purchased and received.',
            ], 403);
        }

        $review = $product->reviews()->create([
            'user_id' => $user->id,
            'rating'  => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review'  => $review->load('user:id,name,avatar'),
        ], 201);
    }

    public function update(Request $request, Review $review)
    {
        $this->authorizeOwnership($request, $review);

        $validated = $request->validate([
            'rating'  => ['sometimes', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review->update($validated);

        return response()->json([
            'message' => 'Review updated successfully.',
            'review'  => $review->fresh()->load('user:id,name,avatar'),
        ]);
    }

    
    public function destroy(Request $request, Review $review)
    {
        $this->authorizeOwnership($request, $review);

        $review->delete();

        return response()->json([
            'message' => 'Review deleted successfully.',
        ]);
    }

   

    private function isVerifiedPurchaser(int $userId, int $productId): bool
    {
        return OrderItem::where('product_id', $productId)
            ->where('status', 'delivered')
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
            ->exists();
    }

    private function authorizeOwnership(Request $request, Review $review): void
    {
        if ($review->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to modify this review.');
        }
    }
}