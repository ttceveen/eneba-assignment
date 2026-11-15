<?php

namespace App\Payments;

use App\Models\Payment;
use InvalidArgumentException;
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
        $id = $payment->getKey();
        $orderId = $payment->external_id;
        if (! $payment->exists || (! is_int($id) && ! is_string($id))
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)
            || ! is_string($orderId) || ! preg_match('/^[A-Z0-9]{1,36}$/D', $orderId)
            || $payment->currency !== 'EUR' || ! $this->sameAmount($payment->amount, $payment->amount)
            || $payment->amount === '0.00') {
            throw new InvalidArgumentException('A saved EUR payment with a valid PayPal Order ID and amount is required.');
        }

        try {
            $order = $this->client->getOrder($orderId);
            $unit = $this->verifiedUnit($order, $payment);
            if (($order['intent'] ?? null) !== 'CAPTURE' || ! $this->sameAmount($unit['amount']['value'] ?? null, $payment->amount)
                || ($unit['amount']['currency_code'] ?? null) !== $payment->currency) {
                throw new RuntimeException('PayPal Sandbox payment verification failed.');
            }
            // Existing captures, including pending ones, must never trigger another capture.
            if (isset($unit['payments']['captures']) && $unit['payments']['captures'] !== []) {
                return $this->captureResult($order, $payment);
            }
            if (($order['status'] ?? null) !== 'APPROVED') {
                return new PaymentResult('pending', $orderId, errorCode: 'payment_unconfirmed',
                    errorMessage: 'The payment is not confirmed.');
            }

            $captured = $this->client->captureOrder($orderId, 'paypal-capture-'.$id);

            return $this->captureResult($captured, $payment);
        } catch (RuntimeException) {
            // Do not preserve provider exceptions or assume that a timed-out capture failed.
            throw new RuntimeException('PayPal Sandbox payment confirmation is unconfirmed; retry the same payment.');
        }
    }

    private function verifiedUnit(array $order, Payment $payment): array
    {
        if (($order['id'] ?? null) !== $payment->external_id || ! is_array($order['purchase_units'] ?? null)
            || ! array_is_list($order['purchase_units']) || count($order['purchase_units']) !== 1
            || ! is_array($order['purchase_units'][0])) {
            throw new RuntimeException('PayPal Sandbox payment verification failed.');
        }

        return $order['purchase_units'][0];
    }

    private function captureResult(array $order, Payment $payment): PaymentResult
    {
        $unit = $this->verifiedUnit($order, $payment);
        $captures = $unit['payments']['captures'] ?? null;
        if (! is_array($captures) || ! array_is_list($captures) || count($captures) !== 1) {
            throw new RuntimeException('PayPal Sandbox payment verification failed.');
        }
        $capture = $captures[0];
        if (! is_array($capture) || ! is_string($capture['id'] ?? null)
            || ! preg_match('/^[A-Z0-9]{1,36}$/D', $capture['id'])
            || ! is_string($capture['status'] ?? null) || trim($capture['status']) === ''
            || ! $this->sameAmount($capture['amount']['value'] ?? null, $payment->amount)
            || ($capture['amount']['currency_code'] ?? null) !== $payment->currency
            || (isset($unit['amount']) && (! $this->sameAmount($unit['amount']['value'] ?? null, $payment->amount)
                || ($unit['amount']['currency_code'] ?? null) !== $payment->currency))) {
            throw new RuntimeException('PayPal Sandbox payment verification failed.');
        }
        if ($capture['status'] === 'COMPLETED' && ($order['status'] ?? null) === 'COMPLETED') {
            return new PaymentResult('succeeded', $payment->external_id, captureId: $capture['id']);
        }

        return new PaymentResult('pending', $payment->external_id, errorCode: 'payment_unconfirmed',
            errorMessage: 'The payment is not confirmed.', captureId: $capture['id']);
    }

    private function sameAmount(mixed $actual, string $expected): bool
    {
        if (! is_string($actual) || ! preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $actual, $parts)) {
            return false;
        }
        $normalized = (ltrim($parts[1], '0') ?: '0').'.'.str_pad($parts[2] ?? '', 2, '0');

        return $normalized === $expected;
    }

    public function supportsSimulation(): bool
    {
        return false;
    }
}
