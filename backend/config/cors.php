<?php

return [
    'paths' => ['api/*', 'up'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [
        'http://localhost:5173',
        env('FRONTEND_URL', 'https://eneba-assignment-phi.vercel.app'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
