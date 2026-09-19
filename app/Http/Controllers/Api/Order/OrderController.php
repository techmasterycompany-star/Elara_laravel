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
use App\Models\Payment;
use App\Models\User;
use App\Http\Controllers\Concerns\AuthorizesOrderOwnership;
use App\Services\PaymentService;



class OrderController extends Controller
{
    use ResolvesCart, AuthorizesOrderOwnership;
 public function __construct(private PaymentService $paymentService) {}
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

    // ⬇️ جديد: فحص رصيد المحفظة - لازم يحصل قبل أي Order::create() أو أي كتابة تانية
    $walletUser = null;

    if ($validated['payment_method'] === 'wallet') {
        if (! $user) {
            abort(422, 'Wallet payment requires a logged-in account.');
        }

        // إعادة جلب اليوزر مع lockForUpdate جوه نفس الـ transaction - مش نفس الـ $user اللي جاي من الـ request
        $walletUser = User::where('id', $user->id)->lockForUpdate()->first();

        if ($walletUser->wallet_balance < $total) {
            abort(422, 'Insufficient wallet balance. Please choose another payment method or top up your wallet.');
        }
    }
    // ⬆️

    $order = Order::create([
        'order_number'   => 'TEMP-' . Str::uuid(),
        'user_id'        => $user?->id,
        'coupon_id'      => $coupon?->id,
        ...$shipping,
        'guest_email'    => $user ? null : $validated['guest_email'],
        'status'         => $validated['payment_method'] === 'wallet' ? 'paid' : 'pending_payment', // ⬅️ اتعدلت
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

    // ⬇️ جديد: خصم الرصيد + تسجيل الـ Payment - بس لو wallet
    if ($validated['payment_method'] === 'wallet') {
        $walletUser->decrement('wallet_balance', $total);

        Payment::create([
            'order_id'       => $order->id,
            'gateway'        => 'wallet',
            'gateway_transaction_id' => 'wallet-' . $order->id . '-' . now()->timestamp,   
            'amount'         => $total,
            'status'         => 'paid',
        ]);
    }
    // ⬆️

    $cart->items()->delete();
    $cart->update(['coupon_id' => null]);

    return $order;
});
$recipientEmail = $user?->email ?? $order->guest_email;

if ($recipientEmail) {
    Mail::to($recipientEmail)->send(new OrderConfirmationMail($order));
}

return response()->json([
    'message' => 'Order placed successfully.',
    'order'   => $order->load('items'),
], 201);
    }

    // ---- #25 Order Status & Tracking ----

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

    $order->load('items');

    if ($order->items->contains(fn ($item) => $item->status !== 'pending')) {
        return response()->json([
            'message' => 'This order can no longer be cancelled.',
        ], 422);
    }

    $refundMessage = null;

    // لو الأوردر كان مدفوع فعلًا، نحاول نرجع الفلوس الأول قبل ما نلغي أي حاجة
    if ($order->status === 'paid') {
        $refundResult = $this->paymentService->refund($order);

        if (! $refundResult->success) {
            // ماوقفناش الإلغاء - بس بنبلّغ إن الرد المالي محتاج تدخل يدوي (زي حالة COD)
            $refundMessage = $refundResult->message;
        }
    }

    DB::transaction(function () use ($order) {
        foreach ($order->items as $item) {
            Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            $item->update(['status' => 'cancelled']);
        }

        // رجوع استخدام الكوبون - لو مبقاش أقل من صفر
        if ($order->coupon_id) {
            $order->coupon()->decrement('used_count');
        }

        $order->update(['status' => 'cancelled']);
    });

    return response()->json([
        'message'        => 'Order cancelled successfully.',
        'refund_message' => $refundMessage, // null لو الرد نجح تلقائيًا أو الأوردر مكانش مدفوع أصلًا
    ]);
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
        
$order = $item->order()->with('items')->first();
$previousStatus = $order->computedStatus();

$item->update(['status' => $validated['status']]);

$order->load('items'); // نعيد تحميل الـ items عشان computedStatus() ياخد القيم الجديدة بعد التحديث
$newStatus = $order->computedStatus();

if ($newStatus !== $previousStatus) {
    $recipientEmail = $order->user?->email ?? $order->guest_email;

    if ($recipientEmail) {
        Mail::to($recipientEmail)->send(new OrderStatusChangedMail($order, $newStatus));
    }
}

return response()->json([
    'message' => 'Order item status updated.',
    'item'    => $item->fresh(),
]);

        return response()->json([
            'message' => 'Order item status updated.',
            'item'    => $item->fresh(),
        ]);
    }

    
}