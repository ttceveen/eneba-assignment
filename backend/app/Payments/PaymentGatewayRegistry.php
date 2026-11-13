<?php

namespace App\Payments;

use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentGatewayRegistry
{
    public function __construct(private Container $container) {}

    public function get(string $code): PaymentGatewayInterface
    {
        $gateways = config('payment.gateways', []);
        if (! array_key_exists($code, $gateways)) {
            throw ValidationException::withMessages(['provider' => 'Unsupported payment provider.']);
        }
        $gateway = $this->container->make($gateways[$code]);
        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new LogicException('The configured gateway must implement PaymentGatewayInterface.');
        }

        return $gateway;
    }

    public function forSimulation(string $code): PaymentGatewayInterface
    {
        $gateway = $this->get($code);
        abort_unless(in_array($code, config('payment.simulation_gateways', []), true) && $gateway->supportsSimulation(), 404);

        return $gateway;
    }
}
