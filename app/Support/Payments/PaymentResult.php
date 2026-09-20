<?php

namespace App\Support\Payments;

class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,          // paid | pending | failed
        public readonly ?string $transactionId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
    ) {}
}