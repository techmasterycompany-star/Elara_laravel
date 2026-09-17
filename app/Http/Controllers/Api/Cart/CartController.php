<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    private const DISCOUNT_TIERS = [
        ['min_subtotal' => 2000, 'discount' => 250],
        ['min_subtotal' => 1000, 'discount' => 100],
    ];

    private const SHIPPING_FEE = 100;

    public function index(Request $request)
    {
        $cart = $this->resolveCart($request, createIfMissing: false);

        if (! $cart) {
            return response()->json([
                'items'    => [],
                'subtotal' => 0,
            ]);
        }

        $cart->load('items.product.images');

        $items = $cart->items->map(function ($item) {
            $product   = $item->product;
            $available = $product && $product->status === 'active';

            return [
                'id'         => $item->id,
                'product_id' => $item->product_id,
                'name'       => $product?->name,
                'quantity'   => $item->quantity,
                'price'      => $available ? $product->currentPrice() : (float) $item->price_at_add,
                'line_total' => $item->lineTotal(),
                'available'  => $available,
                'in_stock'   => $product && $product->stock >= $item->quantity,
            ];
        });

        return response()->json([
            'items'    => $items->values(),
            'subtotal' => $cart->subtotal(),
        ]);
    }

    public function summary(Request $request)
    {
        $cart = $this->resolveCart($request, createIfMissing: false);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'items'            => [],
                'issues'           => [],
                'subtotal'         => 0,
                'discount'         => 0,
                'discount_source'  => null,
                'shipping_fee'     => 0,
                'total'            => 0,
                'can_checkout'     => false,
            ]);
        }

        $cart->load(['items.product', 'coupon']);

        $validItems = collect();
        $issues     = collect();

        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || $product->status !== 'active') {
                $issues->push([
                    'cart_item_id' => $item->id,
                    'product_id'   => $item->product_id,
                    'reason'       => 'unavailable',
                ]);
                continue;
            }

            if ($item->quantity > $product->stock) {
                $issues->push([
                    'cart_item_id' => $item->id,
                    'product_id'   => $item->product_id,
                    'reason'       => 'out_of_stock',
                ]);
                continue;
            }

            $validItems->push($item);
        }

        $subtotal = $validItems->sum(fn ($item) => $item->lineTotal());

        // ---- خصم تلقائي حسب الكمية ----
        $tierDiscount = collect(self::DISCOUNT_TIERS)
            ->first(fn ($tier) => $subtotal >= $tier['min_subtotal'])['discount'] ?? 0;

        // ---- خصم الكوبون (لو متطبق) ----
        $couponDiscount = 0;
        $couponRemoved  = null;
        $coupon         = $cart->coupon;

        if ($coupon) {
            if ($coupon->isValid()) {
                $couponDiscount = $coupon->calculateDiscount((float) $subtotal);
            } else {
                // الكوبون كان متطبق بس بقى غير صالح (خلصت صلاحيته/استخداماته) — نفكه تلقائي
                $reason = $coupon->isExpired() ? 'expired' : 'usage_limit_reached';
                $cart->update(['coupon_id' => null]);
                $couponRemoved = ['code' => $coupon->code, 'reason' => $reason];
            }
        }

        // ---- الأعلى بس بين الاتنين (best-of) ----
        if ($couponDiscount > 0 && $couponDiscount >= $tierDiscount) {
            $discount       = $couponDiscount;
            $discountSource = 'coupon';
        } elseif ($tierDiscount > 0) {
            $discount       = $tierDiscount;
            $discountSource = 'tier';
        } else {
            $discount       = 0;
            $discountSource = null;
        }

        $shippingFee = $validItems->isEmpty() ? 0 : self::SHIPPING_FEE;
        $total       = $subtotal - $discount + $shippingFee;

        return response()->json([
            'items' => $validItems->map(fn ($item) => [
                'id'         => $item->id,
                'product_id' => $item->product_id,
                'name'       => $item->product->name,
                'quantity'   => $item->quantity,
                'price'      => $item->product->currentPrice(),
                'line_total' => $item->lineTotal(),
            ])->values(),
            'issues'          => $issues->values(),
            'coupon_removed'  => $couponRemoved,
            'subtotal'        => round($subtotal, 2),
            'discount'        => round($discount, 2),
            'discount_source' => $discountSource,
            'shipping_fee'    => $shippingFee,
            'total'           => round($total, 2),
            'can_checkout'    => $issues->isEmpty() && $validItems->isNotEmpty(),
        ]);
    }

    public function applyCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $cart = $this->resolveCart($request, createIfMissing: true);

        $coupon = Coupon::where('code', $validated['code'])->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json([
                'message' => 'This coupon code is invalid or expired.',
            ], 422);
        }

        $cart->update(['coupon_id' => $coupon->id]);

        return response()->json([
            'message' => 'Coupon applied.',
            'coupon'  => $coupon->only(['code', 'discount_type', 'discount_value']),
        ]);
    }

    public function removeCoupon(Request $request)
    {
        $cart = $this->resolveCart($request, createIfMissing: false);

        if ($cart) {
            $cart->update(['coupon_id' => null]);
        }

        return response()->json([
            'message' => 'Coupon removed.',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity'   => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if ($product->status !== 'active') {
            return response()->json([
                'message' => 'This product is not available.',
            ], 422);
        }

        $cart = DB::transaction(function () use ($request, $product, $validated) {
            $cart = $this->resolveCart($request, createIfMissing: true);

            $item = $cart->items()->where('product_id', $product->id)->first();

            $newQuantity = $item ? $item->quantity + $validated['quantity'] : $validated['quantity'];

            if ($newQuantity > $product->stock) {
                abort(422, 'Requested quantity exceeds available stock.');
            }

            if ($item) {
                $item->update(['quantity' => $newQuantity]);
            } else {
                $cart->items()->create([
                    'product_id'   => $product->id,
                    'quantity'     => $validated['quantity'],
                    'price_at_add' => $product->currentPrice(),
                ]);
            }

            return $cart;
        });

        return response()->json([
            'message' => 'Item added to cart.',
            'cart'    => $cart->load('items.product'),
        ], 201);
    }

    public function update(Request $request, CartItem $item)
    {
        $this->authorizeOwnership($request, $item);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = $item->product;

        if (! $product || $product->status !== 'active') {
            return response()->json([
                'message' => 'This product is no longer available.',
            ], 422);
        }

        if ($validated['quantity'] > $product->stock) {
            return response()->json([
                'message' => 'Requested quantity exceeds available stock.',
            ], 422);
        }

        $item->update(['quantity' => $validated['quantity']]);

        return response()->json([
            'message' => 'Cart item updated.',
            'item'    => $item->fresh(),
        ]);
    }

    public function destroy(Request $request, CartItem $item)
    {
        $this->authorizeOwnership($request, $item);

        $item->delete();

        return response()->json([
            'message' => 'Item removed from cart.',
        ]);
    }

    private function resolveCart(Request $request, bool $createIfMissing): ?Cart
    {
        $user = $request->user();

        if ($user) {
            return $createIfMissing
                ? $user->cart()->firstOrCreate([])
                : $user->cart()->first();
        }

        $sessionId = $request->header('X-Session-Id');

        if (! $sessionId) {
            abort(422, 'A session id is required for guest carts.');
        }

        return $createIfMissing
            ? Cart::firstOrCreate(['session_id' => $sessionId], ['user_id' => null])
            : Cart::where('session_id', $sessionId)->first();
    }

    private function authorizeOwnership(Request $request, CartItem $item): void
    {
        $cart = $item->cart;
        $user = $request->user();

        if ($user) {
            if ($cart->user_id !== $user->id) {
                abort(403, 'You do not have permission to modify this cart item.');
            }
            return;
        }

        $sessionId = $request->header('X-Session-Id');

        if (! $sessionId || $cart->session_id !== $sessionId) {
            abort(403, 'You do not have permission to modify this cart item.');
        }
    }
}