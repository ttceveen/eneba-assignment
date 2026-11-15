<?php

namespace App\Payments;

use App\Models\Payment;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class PayPalGateway implements PaymentGatewayInterface
{
    // Callback URLs must be supplied by trusted server configuration, never by frontend input.
    public function __construct(
        private PayPalClient $client,
        private string $returnUrl,
        private string $cancelUrl,
    ) {}

    public function create(Payment $payment): PaymentResult
    {
        $id = $payment->getKey();
        if (! $payment->exists || (! is_int($id) && ! is_string($id))
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)) {
            throw new InvalidArgumentException('A saved payment with a valid ID is required.');
        }
        if ($payment->currency !== 'EUR') {
            throw new InvalidArgumentException('PayPal Sandbox supports only EUR payments.');
        }

        // The immutable payment ID identifies this operation across retries and gateway instances.
        $requestId = 'paypal-create-'.$id;
        try {
            $order = $this->client->createOrder($payment->amount, $requestId, $this->returnUrl, $this->cancelUrl);
        } catch (RuntimeException) {
            // An external order may exist even when its response was lost. Do not expose provider details.
            throw new RuntimeException('PayPal Sandbox payment initiation is unconfirmed; retry the same payment.');
        }

        return new PaymentResult('pending', $order['id'], $order['approval_url']);
    }

    public function confirm(Payment $payment, array $context = []): PaymentResult
    {
        throw new LogicException('PayPal Sandbox payment confirmation is not implemented.');
    }

    public function supportsSimulation(): bool
    {
        return false;
    }
}
