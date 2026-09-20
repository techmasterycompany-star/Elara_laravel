<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
'google' => [
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect'      => env('GOOGLE_REDIRECT_URI'),
],
'stripe' => [
    'secret'         => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
'paypal' => [
    'client_id'      => env('PAYPAL_CLIENT_ID'),
    'secret'         => env('PAYPAL_SECRET'),
    'mode'           => env('PAYPAL_MODE', 'sandbox'),
    'webhook_id'     => env('PAYPAL_WEBHOOK_ID'),
    'egp_to_usd_rate' => env('PAYPAL_EGP_TO_USD_RATE', 0.021), 
],
'razorpay' => [
    'key_id'          => env('RAZORPAY_KEY_ID'),
    'key_secret'      => env('RAZORPAY_KEY_SECRET'),
    'webhook_secret'  => env('RAZORPAY_WEBHOOK_SECRET'),
    'egp_to_inr_rate' => env('RAZORPAY_EGP_TO_INR_RATE', 1.75), 
],
];
