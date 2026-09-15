<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
   
    public function index(Request $request)
    {
        $methods = $request->user()->paymentMethods()->latest()->get();

        return response()->json([
            'payment_methods' => $methods,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'gateway'              => ['required', 'string', 'in:stripe,paypal,razorpay'],
            'gateway_customer_id'  => ['nullable', 'string'],
            'token'                => ['required', 'string'],
            'card_brand'           => ['nullable', 'string'],
            'card_last_four'       => ['nullable', 'string', 'size:4'],
            'is_default'           => ['sometimes', 'boolean'],
        ]);

        $method = DB::transaction(function () use ($request, $validated) {
            if (! empty($validated['is_default'])) {
                $request->user()->paymentMethods()->update(['is_default' => false]);
            }

            $isFirstMethod = $request->user()->paymentMethods()->doesntExist();

            return $request->user()->paymentMethods()->create([
                ...$validated,
                'is_default' => $validated['is_default'] ?? $isFirstMethod,
            ]);
        });

        return response()->json([
            'message'        => 'Payment method saved successfully.',
            'payment_method' => $method,
        ], 201);
    }

   
    public function destroy(Request $request, PaymentMethod $paymentMethod)
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            abort(403, 'This payment method does not belong to you.');
        }

        $wasDefault = $paymentMethod->is_default;
        $paymentMethod->delete();

        if ($wasDefault) {
            $request->user()->paymentMethods()->oldest()->first()?->update(['is_default' => true]);
        }

        return response()->json([
            'message' => 'Payment method removed successfully.',
        ]);
    }
}