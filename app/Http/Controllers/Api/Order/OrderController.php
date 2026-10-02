<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Api\Cart\Concerns\ResolvesCart;
use App\Http\Controllers\Concerns\AuthorizesOrderOwnership;
use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusChangedMail;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ResolvesCart, AuthorizesOrderOwnership;

    public function __construct(private PaymentService $paymentService) {}

    private const DISCOUNT_TIERS = [
        ['min_subtotal' => 2000, 'discount' => 250],
        ['min_subtotal' => 1000, 'discount' => 100],
    ];

    private const SHIPPING_FEE = 100;

    /**
     * Public routes (no auth:sanctum) can't see the Bearer token through the
     * default guard, so resolve the user via sanctum and bind it to the request
     * (this also makes ResolvesCart pick the user's cart instead of a guest one).
     */
    private function bindOptionalUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $user;
    }

    public function store(Request $request)
    {
        $user = $this->bindOptionalUser($request);

        $validated = $request->validate([
            'address_id'            => ['nullable', 'exists:addresses,id'],
            'shipping_name'         => ['required_without:address_id', 'string', 'max:255'],
            'shipping_phone'        => ['required_without:address_id', 'string', 'max:50'],
            'shipping_street'       => ['required_without:address_id', 'string', 'max:255'],
            'shipping_city'         => ['required_without:address_id', 'string', 'max:100'],
            'shipping_governorate'  => ['required_without:address_id', 'string', 'max:100'],
            'guest_email'           => [Rule::requiredIf(! $user), 'nullable', 'email'],
            'payment_method'        => ['required', 'string', 'in:cod,stripe,paypal,razorpay,wallet'],
            'newsletter_opt_in'     => ['nullable', 'boolean'],
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

        $cart->load(['items.product']);

        // Early, friendly feedback. The authoritative check is re-done under lock below.
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
            // Lock the cart first: a second concurrent checkout waits here, then finds it empty.
            $lockedCart = Cart::whereKey($cart->id)->lockForUpdate()->first();

            // Products are locked in product_id order so two checkouts can't deadlock.
            $cartItems = $lockedCart
                ? $lockedCart->items()->orderBy('product_id')->get()
                : collect();

            if ($cartItems->isEmpty()) {
                abort(422, 'Your cart is empty.');
            }

            $lineData = [];
            $subtotal = 0;

            foreach ($cartItems as $item) {
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

            // Lock the coupon and re-check validity so the usage limit can't be exceeded.
            $coupon = $lockedCart->coupon_id
                ? Coupon::whereKey($lockedCart->coupon_id)->lockForUpdate()->first()
                : null;

            $couponDiscount = 0;

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

            if ($validated['payment_method'] === 'wallet') {
                if (! $user) {
                    abort(422, 'Wallet payment requires a logged-in account.');
                }

                if ($user->wallet_balance < $total) {
                    abort(422, 'Insufficient wallet balance. Please choose another payment method or top up your wallet.');
                }
            }

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

            $lockedCart->items()->delete();
            $lockedCart->update(['coupon_id' => null]);

            return $order;
        });

        // The order already exists from here on: nothing below may turn the request into a 500.
        try {
            $paymentResult = $this->paymentService->pay($order);
            $paymentStatus = $paymentResult->status;
            $redirectUrl   = $paymentResult->redirectUrl;
            $paymentNote   = null;
        } catch (\Throwable $e) {
            report($e);

            $paymentStatus = 'failed';
            $redirectUrl   = null;
            $paymentNote   = 'Your order was created but the payment could not be started. You can retry payment from your order.';
        }

        if ($request->boolean('newsletter_opt_in')) {
            $email = $user?->email ?? $order->guest_email;

            if ($email) {
                try {
                    NewsletterSubscriber::updateOrCreate(['email' => $email], ['unsubscribed_at' => null]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $recipientEmail = $user?->email ?? $order->guest_email;

        if ($recipientEmail) {
            try {
                Mail::to($recipientEmail)->queue(new OrderConfirmationMail($order));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message'        => 'Order placed successfully.',
            'order'          => $order->fresh()->load('items'),
            'payment_status' => $paymentStatus,
            'redirect_url'   => $redirectUrl,
            'payment_note'   => $paymentNote,
        ], 201);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrderOwnership($request, $order);

        $order->load('items');

        return response()->json([
            'order'          => $order,
            'overall_status' => $order->computedStatus(),
        ]);
    }

    public function index(Request $request)
    {
        /** @var \Illuminate\Pagination\LengthAwarePaginator $orders */
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest()
            ->paginate(10);

        $orders->through(function ($order) {
            $order->overall_status = $order->computedStatus();
            return $order;
        });

        return response()->json($orders);
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorizeOrderOwnership($request, $order);

        [$status, $payload] = DB::transaction(function () use ($order) {
            // Lock + re-check state: two concurrent cancels must not restore stock twice.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->load('items');

            if ($locked->items->contains(fn ($item) => $item->status !== 'pending')) {
                return [422, ['message' => 'This order can no longer be cancelled.']];
            }

            $refundMessage = null;

            if ($locked->status === 'paid') {
                $refundResult = $this->paymentService->refund($locked);

                if (! $refundResult->success) {
                    $refundMessage = $refundResult->message;
                }
            }

            foreach ($locked->items as $item) {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
                $item->update(['status' => 'cancelled']);
            }

            if ($locked->coupon_id) {
                $locked->coupon()->decrement('used_count');
            }

            $locked->update(['status' => 'cancelled']);

            return [200, [
                'message'        => 'Order cancelled successfully.',
                'refund_message' => $refundMessage,
            ]];
        });

        return response()->json($payload, $status);
    }

    public function reorder(Request $request, Order $order)
    {
        $this->authorizeOrderOwnership($request, $order);

        $order->load('items');

        $cart = $this->resolveCart($request, createIfMissing: true);

        $skipped = collect();

        DB::transaction(function () use ($order, $cart, &$skipped) {
            foreach ($order->items as $orderItem) {
                $product = Product::where('id', $orderItem->product_id)->first();

                if (! $product || $product->status !== 'active') {
                    $skipped->push(['product_id' => $orderItem->product_id, 'reason' => 'unavailable']);
                    continue;
                }

                $cartItem    = $cart->items()->where('product_id', $product->id)->first();
                $newQuantity = $cartItem ? $cartItem->quantity + $orderItem->quantity : $orderItem->quantity;

                if ($newQuantity > $product->stock) {
                    $skipped->push(['product_id' => $product->id, 'reason' => 'out_of_stock']);
                    continue;
                }

                if ($cartItem) {
                    $cartItem->update(['quantity' => $newQuantity]);
                } else {
                    $cart->items()->create([
                        'product_id'   => $product->id,
                        'quantity'     => $orderItem->quantity,
                        'price_at_add' => $product->currentPrice(),
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Items added to your cart from this order.',
            'cart'    => $cart->load('items.product'),
            'skipped' => $skipped->values(),
        ]);
    }

    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,processing,shipped,delivered,cancelled'],
        ]);

        $user = $request->user();

        if ($user->isSeller()) {
            $seller = $user->seller;

            // Check the seller record exists first: null === null would match admin-owned items.
            if (! $seller || ! $seller->isApproved() || $item->seller_id === null || $item->seller_id !== $seller->id) {
                abort(403, 'You do not have permission to update this order item.');
            }
        } elseif (! $user->isAdmin()) {
            abort(403, 'You do not have permission to update order items.');
        }

        [$order, $previousStatus, $newStatus, $updatedItem] = DB::transaction(function () use ($item, $validated, $user) {
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $lockedItem = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            // Re-check against the locked, current state.
            if ($user->isSeller()) {
                $allowedTransitions = [
                    'pending'    => 'processing',
                    'processing' => 'shipped',
                ];

                if (($allowedTransitions[$lockedItem->status] ?? null) !== $validated['status']) {
                    abort(403, 'Sellers can only move an item from pending to processing, or processing to shipped.');
                }
            } elseif (in_array($lockedItem->status, ['delivered', 'cancelled'], true)) {
                abort(422, 'A delivered or cancelled item can no longer be changed.');
            }

            $order->load('items');
            $previousStatus = $order->computedStatus();

            if ($validated['status'] === 'cancelled') {
                Product::where('id', $lockedItem->product_id)->increment('stock', $lockedItem->quantity);
            }

            $lockedItem->update(['status' => $validated['status']]);

            $order->load('items');

            return [$order, $previousStatus, $order->computedStatus(), $lockedItem];
        });

        if ($newStatus !== $previousStatus) {
            $recipientEmail = $order->user?->email ?? $order->guest_email;

            if ($recipientEmail) {
                try {
                    Mail::to($recipientEmail)->queue(new OrderStatusChangedMail($order, $newStatus));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json([
            'message' => 'Order item status updated.',
            'item'    => $updatedItem->fresh(),
        ]);
    }
}