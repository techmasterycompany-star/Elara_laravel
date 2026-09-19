<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * نقطة الدخول الوحيدة اللي Stripe بيكلمها.
     * الكنترولر نفسه معندوش أي منطق - كل حاجة اتحطت جوه StripeGateway
     * عشان لو ضفنا PayPal/Razorpay بعدين، كل واحد ليه method منفصلة هنا
     * بتنده على الـ gateway بتاعه، من غير ما نلمس الكود القديم.
     */
    public function stripe(Request $request, StripeGateway $stripeGateway)
    {
        $stripeGateway->handleWebhook($request);

        return response()->json(['received' => true]);
    }
}