<?php

namespace App\Payments;

use App\Models\Payment;

interface PaymentGatewayInterface
{
    /** Must use the immutable internal payment ID as the provider idempotency key. */
    public function create(Payment $payment): PaymentResult;

    /** Real adapters must verify a trusted provider API; never trust client success. */
    public function confirm(Payment $payment, array $context = []): PaymentResult;

    public function supportsSimulation(): bool;
}
