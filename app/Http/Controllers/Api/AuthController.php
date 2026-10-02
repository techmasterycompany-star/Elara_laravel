<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
                'name'              => $validated['name'],
                'email'             => $validated['email'] ?? null,
                'phone'             => $validated['phone'] ?? null,
                'password'          => $validated['password'],
                'role'              => 'customer',
                'is_active'         => true,
                'email_verified_at' => is_null($validated['email'] ?? null) ? now() : null,
            ]);

            if (! is_null($user->email)) {
                $user->sendEmailVerificationNotification();
            }

            return $user;
        });

        // Guest cart merge on registration (Issue #21)
        $this->mergeGuestCart($user, $request->header('X-Session-Id'));

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

        // Guest cart merge on login (Issue #21)
        $this->mergeGuestCart($user, $request->header('X-Session-Id'));

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

    /**
     * Merge a guest cart (identified by session id) into the user's cart.
     * A failure here must never block login/registration, so it is logged and swallowed.
     */
    private function mergeGuestCart(User $user, ?string $sessionId): void
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