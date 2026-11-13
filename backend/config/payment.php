<?php

use App\Payments\FakeCardGateway;

return [
    'demo_enabled' => (bool) env('PAYMENT_DEMO_ENABLED', false),
    'gateways' => ['fake_card' => FakeCardGateway::class],
    'simulation_gateways' => ['fake_card'],
];
