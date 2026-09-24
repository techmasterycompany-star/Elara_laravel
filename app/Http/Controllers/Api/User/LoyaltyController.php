<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    // ---- #41 Points balance (sum of the ledger, never stored directly) ----
    public function balance(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'balance' => (int) $user->loyaltyPoints()->sum('points'),
        ]);
    }

    // ---- #41 Points transaction history ----
    public function history(Request $request)
    {
        $entries = $request->user()
            ->loyaltyPoints()
            ->latest()
            ->paginate(20);

        return response()->json($entries);
    }
}