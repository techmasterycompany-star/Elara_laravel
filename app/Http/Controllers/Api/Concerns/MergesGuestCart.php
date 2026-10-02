<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait MergesGuestCart
{
    /**
     * Merge a guest cart (identified by session id) into the user's cart.
     * A failure here must never block login/registration, so it is logged and swallowed.
     */
    protected function mergeGuestCart(User $user, ?string $sessionId): void
    {
        if (! $sessionId) {
            return;
        }

        try {
            DB::transaction(function () use ($user, $sessionId) {
                $guestCart = Cart::with('items.product')
                    ->where('session_id', $sessionId)
                    ->whereNull('user_id')
                    ->first();

                if (! $guestCart) {
                    return;
                }

                $userCart = $user->cart()->firstOrCreate([]);

                foreach ($guestCart->items as $guestItem) {
                    $product = $guestItem->product;

                    // Skip products that were deleted, are not live, or are out of stock
                    if (! $product || $product->status !== 'active' || $product->stock < 1) {
                        continue;
                    }

                    $existingItem = $userCart->items()
                        ->where('product_id', $guestItem->product_id)
                        ->first();

                    $newQuantity = $existingItem
                        ? $existingItem->quantity + $guestItem->quantity
                        : $guestItem->quantity;

                    // Cap at available stock so the merge never creates an impossible quantity
                    $newQuantity = min($newQuantity, $product->stock);

                    if ($existingItem) {
                        $existingItem->update(['quantity' => $newQuantity]);
                    } else {
                        $userCart->items()->create([
                            'product_id'   => $guestItem->product_id,
                            'quantity'     => $newQuantity,
                            'price_at_add' => $guestItem->price_at_add,
                        ]);
                    }
                }

                $guestCart->delete();
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
