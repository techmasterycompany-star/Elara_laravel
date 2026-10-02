<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use Illuminate\Http\Request;

trait AuthorizesOrderOwnership
{
  
    protected function authorizeOrderOwnership(Request $request, Order $order): void
    {
       $user = $request->user('sanctum');
        if ($order->user_id) {
            if (! $user || $order->user_id !== $user->id) {
                abort(403, 'This order does not belong to you.');
            }

            return;
        }

        $validated = $request->validate([
            'order_number' => ['required', 'string'],
            'guest_email'  => ['required', 'email'],
        ]);

        $orderNumberMatches = hash_equals($order->order_number, $validated['order_number']);
        $emailMatches       = strcasecmp($order->guest_email ?? '', $validated['guest_email']) === 0;

        if (! $orderNumberMatches || ! $emailMatches) {
            abort(403, 'This order does not belong to you.');
        }
    }
}