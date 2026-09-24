<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\SellerPayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerPayoutController extends Controller
{
    // ---- #39 Earnings summary (by period) ----
    public function earnings(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = OrderItem::where('seller_id', $seller->id)->where('status', 'delivered');

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $earned = (clone $query)->get()->sum(fn ($item) => $item->lineTotal());
        $itemsCount = (clone $query)->count();

        return response()->json([
            'period' => [
                'from' => $validated['date_from'] ?? null,
                'to'   => $validated['date_to'] ?? null,
            ],
            'delivered_items'    => $itemsCount,
            'earnings_in_period' => round($earned, 2),
            'available_balance'  => round($this->earnedBalance($seller->id), 2),
        ]);
    }

    // ---- #39 Request a payout, validated against actual earned balance ----
    public function requestPayout(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $payout = DB::transaction(function () use ($seller, $validated) {
            $balance = $this->earnedBalance($seller->id, lock: true);

            if ($validated['amount'] > $balance) {
                abort(422, 'Requested amount exceeds your available balance.');
            }

            return SellerPayout::create([
                'seller_id' => $seller->id,
                'amount'    => $validated['amount'],
                'status'    => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Payout request submitted successfully.',
            'payout'  => $payout,
        ], 201);
    }

    // ---- #39 Payout history/status ----
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $payouts = $seller->payouts()->latest()->paginate(20);

        return response()->json($payouts);
    }

    // ---- helper: completed order items minus prior payouts (pending + paid) ----
    private function earnedBalance(int $sellerId, bool $lock = false): float
    {
        $earned = OrderItem::where('seller_id', $sellerId)
            ->where('status', 'delivered')
            ->get()
            ->sum(fn ($item) => $item->lineTotal());

        $payoutsQuery = SellerPayout::where('seller_id', $sellerId)
            ->whereIn('status', ['pending', 'paid']);

        if ($lock) {
            $payoutsQuery->lockForUpdate();
        }

        $alreadyTaken = $payoutsQuery->sum('amount');

        return max(0, $earned - $alreadyTaken);
    }
}