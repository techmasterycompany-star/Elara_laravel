<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Api\Cart\Concerns\ResolvesCart;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusChangedMail;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    use ResolvesCart;

    private const DISCOUNT_TIERS = [
        ['min_subtotal' => 2000, 'discount' => 250],
        ['min_subtotal' => 1000, 'discount' => 100],
    ];

    private const SHIPPING_FEE = 100;

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'address_id'            => ['nullable', 'exists:addresses,id'],
            'shipping_name'         => ['required_without:address_id', 'string', 'max:255'],
            'shipping_phone'        => ['required_without:address_id', 'string', 'max:50'],
            'shipping_street'       => ['required_without:address_id', 'string', 'max:255'],
            'shipping_city'         => ['required_without:address_id', 'string', 'max:100'],
            'shipping_governorate'  => ['required_without:address_id', 'string', 'max:100'],
            'guest_email'           => [Rule::requiredIf(! $user), 'nullable', 'email'],
            'payment_method'        => ['required', 'string', 'in:cod,stripe,paypal,razorpay,wallet'],
        ]);

        if (! $user && ! empty($validated['address_id'])) {
            abort(422, 'Guests cannot checkout with a saved address.');
        }

        if (! empty($validated['address_id'])) {
            $address = Address::where('id', $validated['address_id'])
                ->where('user_id', $user->id)
                ->first();

            if (! $address) {
                abort(403, 'This address does not belong to you.');
            }

            $shipping = [
                'shipping_name'        => $user->name,
                'shipping_phone'       => $address->phone,
                'shipping_street'      => $address->street,
                'shipping_city'        => $address->city,
                'shipping_governorate' => $address->governorate,
            ];
        } else {
            $shipping = [
                'shipping_name'        => $validated['shipping_name'],
                'shipping_phone'       => $validated['shipping_phone'],
                'shipping_street'      => $validated['shipping_street'],
                'shipping_city'        => $validated['shipping_city'],
                'shipping_governorate' => $validated['shipping_governorate'],
            ];
        }

        $cart = $this->resolveCart($request, createIfMissing: false);

        if (! $cart || $cart->items->isEmpty()) {
            abort(422, 'Your cart is empty.');
        }

        $cart->load(['items.product', 'coupon']);

        $issues = collect();

        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || $product->status !== 'active') {
                $issues->push(['product_id' => $item->product_id, 'reason' => 'unavailable']);
                continue;
            }

            if ($item->quantity > $product->stock) {
                $issues->push(['product_id' => $item->product_id, 'reason' => 'out_of_stock']);
            }
        }

        if ($issues->isNotEmpty()) {
            return response()->json([
                'message' => 'Some items in your cart are no longer available.',
                'issues'  => $issues->values(),
            ], 422);
        }

        $order = DB::transaction(function () use ($user, $cart, $shipping, $validated) {
            $lineData = [];
            $subtotal = 0;

            foreach ($cart->items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();

                if (! $product || $product->status !== 'active' || $product->stock < $item->quantity) {
                    abort(422, "Product #{$item->product_id} is no longer available in the requested quantity.");
                }

                $price = $product->currentPrice();

                $lineData[] = [
                    'product'  => $product,
                    'quantity' => $item->quantity,
                    'price'    => $price,
                ];

                $subtotal += $price * $item->quantity;
            }

            $tierDiscount = collect(self::DISCOUNT_TIERS)
                ->first(fn ($tier) => $subtotal >= $tier['min_subtotal'])['discount'] ?? 0;

            $couponDiscount = 0;
            $coupon = $cart->coupon;

            if ($coupon && $coupon->isValid()) {
                $couponDiscount = $coupon->calculateDiscount((float) $subtotal);
            } else {
                $coupon = null;
            }

            if ($couponDiscount > 0 && $couponDiscount >= $tierDiscount) {
                $discount = $couponDiscount;
            } else {
                $discount = $tierDiscount;
                $coupon   = null;
            }

            $shippingFee = self::SHIPPING_FEE;
            $total       = $subtotal - $discount + $shippingFee;

            $order = Order::create([
                'order_number'   => 'TEMP-' . Str::uuid(),
                'user_id'        => $user?->id,
                'coupon_id'      => $coupon?->id,
                ...$shipping,
                'guest_email'    => $user ? null : $validated['guest_email'],
                'status'         => 'pending_payment',
                'subtotal'       => round($subtotal, 2),
                'discount'       => round($discount, 2),
                'shipping_fee'   => $shippingFee,
                'total'          => round($total, 2),
                'payment_method' => $validated['payment_method'],
            ]);

            $order->update(['order_number' => Order::generateOrderNumber($order->id)]);

            foreach ($lineData as $line) {
                $product = $line['product'];

                $product->decrement('stock', $line['quantity']);

                $order->items()->create([
                    'product_id'   => $product->id,
                    'seller_id'    => $product->seller_id,
                    'product_name' => $product->name,
                    'product_sku'  => $product->sku,
                    'quantity'     => $line['quantity'],
                    'price'        => $line['price'],
                    'status'       => 'pending',
                ]);
            }

            if ($coupon) {
                $coupon->increment('used_count');
            }

            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);

            return $order;
        });

        return response()->json([
            'message' => 'Order placed successfully.',
            'order'   => $order->load('items'),
        ], 201);
    }

    // ---- #25 Order Status & Tracking ----

    public function show(Request $request, Order $order)
    {
        $this->authorizeCustomer($request, $order);

        $order->load('items');

        return response()->json([
            'order'          => $order,
            'overall_status' => $order->computedStatus(),
        ]);
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorizeCustomer($request, $order);

        $order->load('items');

        if ($order->items->contains(fn ($item) => $item->status !== 'pending')) {
            return response()->json([
                'message' => 'This order can no longer be cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
                $item->update(['status' => 'cancelled']);
            }

            $order->update(['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Order cancelled successfully.',
        ]);
    }

    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,processing,shipped,delivered,cancelled'],
        ]);

        $user = $request->user();

        if ($user->isSeller()) {
            if ($item->seller_id !== $user->seller?->id) {
                abort(403, 'You do not have permission to update this order item.');
            }

            $allowedTransitions = [
                'pending'    => 'processing',
                'processing' => 'shipped',
            ];

            if (($allowedTransitions[$item->status] ?? null) !== $validated['status']) {
                abort(403, 'Sellers can only move an item from pending to processing, or processing to shipped.');
            }
        } elseif (! $user->isAdmin()) {
            abort(403, 'You do not have permission to update order items.');
        }

        $item->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Order item status updated.',
            'item'    => $item->fresh(),
        ]);
    }

    private function authorizeCustomer(Request $request, Order $order): void
    {
        $user = $request->user();

        if (! $user || $order->user_id !== $user->id) {
            abort(403, 'You do not have permission to access this order.');
        }
    }
}