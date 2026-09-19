<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletGateway implements PaymentGateway
{
    /**
     * خصم مباشر من رصيد اليوزر - بعكس Stripe/COD، هنا الدفع بيحصل
     * ويتأكد منه في نفس اللحظة، مفيش انتظار لأي بوابة خارجية.
     */
    public function charge(Order $order): PaymentResult
    {
        if (! $order->user_id) {
            return new PaymentResult(
                success: false,
                status: 'failed',
                transactionId: null,
                redirectUrl: null,
                message: 'Wallet payment requires a logged-in account, not available for guest checkout.',
            );
        }

        // القفل هنا هو أهم سطر في الكلاس كله - هيتشرح تحت
        $result = DB::transaction(function () use ($order) {
            $user = $order->user()->lockForUpdate()->first();

            if ($user->wallet_balance < $order->total) {
                return new PaymentResult(
                    success: false,
                    status: 'failed',
                    transactionId: null,
                    redirectUrl: null,
                    message: 'Insufficient wallet balance.',
                );
            }

            $user->decrement('wallet_balance', $order->total);

            return new PaymentResult(
                success: true,
                status: 'paid', // بعكس Stripe/COD - هنا النتيجة نهائية فورًا، مفيش webhook أو تأكيد لاحق
                transactionId: 'wallet-' . $order->id . '-' . now()->timestamp,
                redirectUrl: null,
                message: null,
            );
        });

        return $result;
    }

    /**
     * استرجاع المبلغ للمحفظة - أبسط refund موجود في المشروع كله،
     * لأننا احنا اللي متحكمين في الرصيد بالكامل، مش محتاجين نكلم أي بوابة خارجية.
     */
    public function refund(Payment $payment): PaymentResult
    {
        $order = $payment->order;

        DB::transaction(function () use ($order, $payment) {
            $order->user()->increment('wallet_balance', $payment->amount);
            $payment->update(['status' => 'refunded']);
        });

        return new PaymentResult(
            success: true,
            status: 'refunded',
            transactionId: $payment->transaction_id,
            redirectUrl: null,
            message: null,
        );
    }

    /**
     * محفظتنا الداخلية - مفيش أي جهة خارجية تبعتلنا webhook خالص.
     */
    public function handleWebhook(Request $request): void
    {
        // لا يوجد - الدفع بيتأكد فورًا في charge()، مفيش تأكيد لاحق من حد تاني
    }
}