<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Support\Payments\StripeGateway;
use Illuminate\Http\Request;
use App\Support\Payments\PayPalGateway; 

class WebhookController extends Controller
{
   
    public function stripe(Request $request, StripeGateway $stripeGateway)
    {
        $stripeGateway->handleWebhook($request);

        return response()->json(['received' => true]);
    }
    public function paypal(Request $request, PayPalGateway $paypalGateway)
{
    $paypalGateway->handleWebhook($request);

    return response()->json(['received' => true]);
}
}