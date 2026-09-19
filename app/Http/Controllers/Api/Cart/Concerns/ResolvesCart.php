<?php

namespace App\Http\Controllers\Api\Cart\Concerns;

use App\Models\Cart;
use Illuminate\Http\Request;

trait ResolvesCart
{
    protected function resolveCart(Request $request, bool $createIfMissing): ?Cart
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
}