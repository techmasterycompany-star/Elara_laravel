<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\SellerPayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerPayoutController extends Controller
{
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

        $earnedCents = (clone $query)->get()
            ->sum(fn ($item) => $this->toCents($item->lineTotal()));
        $itemsCount = (clone $query)->count();

        return response()->json([
            'period' => [
                'from' => $validated['date_from'] ?? null,
                'to'   => $validated['date_to'] ?? null,
            ],
            'delivered_items'    => $itemsCount,
            'earnings_in_period' => $earnedCents / 100,
            'available_balance'  => $this->balanceInCents($seller->id) / 100,
        ]);
    }

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

        $amountCents = $this->toCents($validated['amount']);

        $payout = DB::transaction(function () use ($seller, $validated, $amountCents) {
            // Serialize concurrent payout requests for the same seller.
            $seller->newQuery()->whereKey($seller->id)->lockForUpdate()->first();

            if ($amountCents > $this->balanceInCents($seller->id)) {
                abort(422, 'Requested amount exceeds your available balance.');
            }

            return SellerPayout::create([
                'seller_id' => $seller->id,
                'amount'    => $amountCents / 100,
                'status'    => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Payout request submitted successfully.',
            'payout'  => $payout,
        ], 201);
    }

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

    /**
     * Available balance in cents (integer): delivered earnings minus
     * pending/paid payouts. Integer math avoids float errors such as
     * 99.99 - 50.00 = 49.989999...
     */
    private function balanceInCents(int $sellerId): int
    {
        $earned = OrderItem::where('seller_id', $sellerId)
            ->where('status', 'delivered')
            ->get()
            ->sum(fn ($item) => $this->toCents($item->lineTotal()));

        $taken = SellerPayout::where('seller_id', $sellerId)
            ->whereIn('status', ['pending', 'paid'])
            ->pluck('amount')
            ->sum(fn ($amount) => $this->toCents($amount));

        return max(0, $earned - $taken);
    }

    private function toCents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}