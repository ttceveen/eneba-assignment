<?php

use App\Payments\FakeCardGateway;

return [
    'demo_enabled' => (bool) env('PAYMENT_DEMO_ENABLED', false),
    'gateways' => ['fake_card' => FakeCardGateway::class],
    'simulation_gateways' => ['fake_card'],
    'paypal' => [
        'enabled' => (bool) env('PAYPAL_ENABLED', false),
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        'merchant_id' => env('PAYPAL_MERCHANT_ID', ''),
    ],
];
