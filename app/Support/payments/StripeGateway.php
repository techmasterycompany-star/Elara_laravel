<?php

namespace App\Support\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeGateway implements PaymentGateway
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * بننشئ Stripe Checkout Session ونرجّع رابطها للفرونتاند.
     * الدفع الفعلي بيحصل على صفحة Stripe نفسها، مش عندنا.
     * الحالة النهائية (paid/failed) هتوصلنا لاحقًا عن طريق الـ webhook، مش هنا.
     */
    public function charge(Order $order): PaymentResult
    {
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency'     => 'egp',
                    'unit_amount'  => (int) round($order->total * 100), // Stripe بياخد أصغر وحدة عملة (قروش)
                    'product_data' => [
                        'name' => "GlowThera Order #{$order->order_number}",
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'order_id' => $order->id, // أهم سطر - ده اللي هيربطنا بالأوردر لما الـ webhook يوصل
            ],
            'success_url' => config('app.frontend_url') . '/orders/' . $order->id . '?payment=success',
            'cancel_url'  => config('app.frontend_url') . '/orders/' . $order->id . '?payment=cancelled',
        ]);

        return new PaymentResult(
            success: true,
            status: 'pending', // لسه ملهوش نتيجة نهائية لحد ما اليوزر يدفع فعليًا على صفحة Stripe
            transactionId: $session->id,
            redirectUrl: $session->url,
            message: null,
        );
    }

    /**
     * لسه مش هنطبقها فعليًا دلوقتي - المفروض تستخدم Stripe\Refund لعمل استرجاع.
     * حطيناها هنا بس عشان نطبّق الـ interface بالكامل، وهنبنيها لما نوصل لنظام الـ refunds.
     */
    public function refund(Payment $payment): PaymentResult
    {
        return new PaymentResult(
            success: false,
            status: 'failed',
            transactionId: $payment->transaction_id,
            redirectUrl: null,
            message: 'Refunds are not implemented yet.',
        );
    }

    /**
     * Stripe بيبعتلنا event لما حاجة تحصل (نجاح دفع، فشل، الخ).
     * لازم نتأكد الأول إن الـ request فعلاً جاي من Stripe (مش حد بيحاول يزوّر webhook)،
     * وبعدين نتعامل مع الـ event نفسه.
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            abort(400, 'Invalid webhook signature.');
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $orderId = $session->metadata->order_id ?? null;

            if (! $orderId) {
                Log::warning('Stripe webhook missing order_id in metadata.', ['session_id' => $session->id]);
                return;
            }

            $order = Order::find($orderId);

            if (! $order) {
                Log::warning('Stripe webhook references a non-existent order.', ['order_id' => $orderId]);
                return;
            }

            // نفس فحص الـ idempotency - لو الأوردر دفع خلاص، منعملش حاجة تاني
            $existingPayment = Payment::where('order_id', $order->id)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                return;
            }

            DB::transaction(function () use ($order, $session) {
                Payment::updateOrCreate(
                    ['order_id' => $order->id, 'gateway' => 'stripe'],
                    [
                        'transaction_id' => $session->payment_intent ?? $session->id,
                        'amount'         => $order->total,
                        'status'         => 'paid',
                    ]
                );

                $order->update(['status' => 'paid']);
            });
        }

        // أنواع events تانية (زي checkout.session.expired) ممكن نضيفهم لاحقًا لو احتجنا
    }
}