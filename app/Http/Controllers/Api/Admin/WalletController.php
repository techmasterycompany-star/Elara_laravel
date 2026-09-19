<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * الأدمن يضيف رصيد يدويًا لأي يوزر - v1 بسيط، مفيش أي بوابة دفع
     * فعلية مربوطة بالـ top-up نفسه (زي ما كان متفق في الـ Issue الأصلي).
     */
    public function topUp(Request $request, User $user)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $user->lockForUpdate()->increment('wallet_balance', $validated['amount']);
        });

        return response()->json([
            'message'        => 'Wallet topped up successfully.',
            'user_id'        => $user->id,
            'new_balance'    => $user->fresh()->wallet_balance,
        ]);
    }
}