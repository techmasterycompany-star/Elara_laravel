<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerPayout;
use Illuminate\Http\Request;

class AdminPayoutController extends Controller
{
    // ---- #39 Admin: mark a payout as paid ----
    public function markPaid(SellerPayout $payout)
    {
        if ($payout->status === 'paid') {
            return response()->json([
                'message' => 'This payout has already been paid.',
            ], 422);
        }

        $payout->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        return response()->json([
            'message' => 'Payout marked as paid.',
            'payout'  => $payout->fresh(),
        ]);
    }
}