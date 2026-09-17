<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required_without:phone', 'nullable', 'email', 'unique:users,email'],
            'phone'    => ['required_without:email', 'nullable', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

       $user = DB::transaction(function () use ($validated) {
    $user = User::create([
        'name'     => $validated['name'],
        'email'    => $validated['email'] ?? null,
        'phone'    => $validated['phone'] ?? null,
        'password' => $validated['password'],
        'role'     => 'customer',
        'is_active' => true,
        'email_verified_at' => is_null($validated['email'] ?? null) ? now() : null, 
    ]);

    if (! is_null($user->email)) {                   
        $user->sendEmailVerificationNotification();   
    }

    return $user;
});

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::where($field, $request->login)->first();

        if (! $user || is_null($user->password) || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['This account has been suspended.'],
            ]);
        }

        // ---- Guest Cart Merge (Issue #21) ----
        $sessionId = $request->header('X-Session-Id');

        if ($sessionId) {
            DB::transaction(function () use ($user, $sessionId) {
                $guestCart = Cart::where('session_id', $sessionId)->whereNull('user_id')->first();

                if (! $guestCart) {
                    return;
                }

                $userCart = $user->cart()->firstOrCreate([]);

                foreach ($guestCart->items as $guestItem) {
                    $product = $guestItem->product;

                    if (! $product) {
                        continue;
                    }

                    $existingItem = $userCart->items()->where('product_id', $guestItem->product_id)->first();

                    $newQuantity = $existingItem
                        ? $existingItem->quantity + $guestItem->quantity
                        : $guestItem->quantity;

                    // نحدد الكمية عند الـ stock المتاح عشان الـ merge ميبعتش quantity غير منطقية
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
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}