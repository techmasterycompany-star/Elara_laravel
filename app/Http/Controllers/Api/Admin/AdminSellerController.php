<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use Illuminate\Http\Request;

class AdminSellerController extends Controller
{
    // ---- #36 Approve or reject a seller's store profile ----
    public function updateStatus(Request $request, Seller $seller)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
        ]);

        $seller->update(['status' => $validated['status']]);

        return response()->json([
            'message' => "Seller {$validated['status']} successfully.",
            'seller'  => $seller->fresh(),
        ]);
    }
}