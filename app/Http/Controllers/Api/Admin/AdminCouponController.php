<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;

class AdminCouponController extends Controller
{
    
    public function index()
    {
        $coupons = Coupon::latest()->paginate(20);

        return response()->json($coupons);
    }

   
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'           => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'discount_type'  => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'expires_at'     => ['nullable', 'date', 'after:today'],
            'usage_limit'    => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return response()->json([
                'message' => 'A percentage discount cannot exceed 100.',
            ], 422);
        }

        $coupon = Coupon::create($validated);

        return response()->json([
            'message' => 'Coupon created successfully.',
            'coupon'  => $coupon,
        ], 201);
    }

  
    public function update(Request $request, Coupon $coupon)
    {
        $validated = $request->validate([
            'code'           => ['sometimes', 'string', 'max:50', 'unique:coupons,code,' . $coupon->id],
            'discount_type'  => ['sometimes', 'in:percent,fixed'],
            'discount_value' => ['sometimes', 'numeric', 'min:0'],
            'expires_at'     => ['nullable', 'date'],
            'usage_limit'    => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $validated['discount_type'] ?? $coupon->discount_type;
        $value = $validated['discount_value'] ?? $coupon->discount_value;

        if ($type === 'percent' && $value > 100) {
            return response()->json([
                'message' => 'A percentage discount cannot exceed 100.',
            ], 422);
        }

        $coupon->update($validated);

        return response()->json([
            'message' => 'Coupon updated successfully.',
            'coupon'  => $coupon->fresh(),
        ]);
    }

   
    public function deactivate(Coupon $coupon)
    {
        $coupon->update(['expires_at' => now()->subDay()]);

        return response()->json([
            'message' => 'Coupon deactivated successfully.',
            'coupon'  => $coupon->fresh(),
        ]);
    }

   
    public function stats(Coupon $coupon)
    {
        $ordersUsingCoupon = $coupon->orders()->where('status', '!=', 'cancelled');

        return response()->json([
            'code'                => $coupon->code,
            'usage_limit'         => $coupon->usage_limit,
            'used_count'          => $coupon->used_count,
            'remaining_uses'      => $coupon->usage_limit !== null
                ? max(0, $coupon->usage_limit - $coupon->used_count)
                : null,
            'is_valid'            => $coupon->isValid(),
            'orders_count'        => $ordersUsingCoupon->count(),
            'total_discount_given' => $ordersUsingCoupon->sum('discount'),
        ]);
    }
}