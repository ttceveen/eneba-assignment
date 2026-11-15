<?php

namespace App\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class PayPalClient
{
    private const TOKEN_URL = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    public function accessToken(): string
    {
        if (config('payment.paypal.enabled') !== true) {
            throw new RuntimeException('PayPal Sandbox is disabled.');
        }

        $clientId = config('payment.paypal.client_id');
        $secret = config('payment.paypal.client_secret');
        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('PayPal Sandbox credentials are not configured.');
        }

        // Credential rotation must not reuse a token belonging to the previous credentials.
        $cacheKey = 'paypal:sandbox:oauth:'.hash('sha256', json_encode([$clientId, $secret], JSON_THROW_ON_ERROR));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $requestedAt = now();
        try {
            $response = Http::asForm()->acceptJson()->withBasicAuth($clientId, $secret)
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            // Do not chain the transport exception: it may contain request details.
            throw new RuntimeException('PayPal Sandbox authentication connection failed.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('PayPal Sandbox authentication was rejected.');
        }
        if ($response->serverError()) {
            throw new RuntimeException('PayPal Sandbox authentication is temporarily unavailable.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('PayPal Sandbox authentication failed.');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        if (! is_string($token) || trim($token) === '' || ! is_int($expiresIn) || $expiresIn <= 0
            || $response->json('token_type') !== 'Bearer') {
            throw new RuntimeException('PayPal Sandbox returned an invalid authentication response.');
        }

        // Start at request time and refresh early, rather than serving a nearly expired token.
        $cacheUntil = $requestedAt->addSeconds($expiresIn)->subSeconds(30);
        if ($cacheUntil->isFuture()) {
            Cache::put($cacheKey, $token, $cacheUntil);
        }

        return $token;
    }

    /** @return array{id: string, approval_url: string} */
    public function createOrder(string $amount, string $requestId, string $returnUrl, string $cancelUrl): array
    {
        // EUR uses exactly two decimal places; preserve the decimal string throughout.
        if (! preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $amount) || $amount === '0.00') {
            throw new InvalidArgumentException('The EUR amount must be a positive decimal string with two decimal places.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{1,38}$/D', $requestId)) {
            throw new InvalidArgumentException('A stable PayPal request ID of at most 38 characters is required.');
        }
        foreach ([$returnUrl, $cancelUrl] as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                throw new InvalidArgumentException('Return and cancel URLs must be absolute HTTP or HTTPS URLs without credentials.');
            }
        }

        $token = $this->accessToken();
        try {
            $response = Http::asJson()->acceptJson()->withToken($token)
                ->withHeaders(['PayPal-Request-Id' => $requestId])
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [['amount' => ['currency_code' => 'EUR', 'value' => $amount]]],
                    'payment_source' => ['paypal' => ['experience_context' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                        'shipping_preference' => 'NO_SHIPPING',
                    ]]],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('PayPal Sandbox order creation connection failed; retry with the same request ID.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayPal Sandbox order creation failed; retry only with the same request ID.');
        }

        $body = $response->json();
        if (! is_array($body) || ! is_string($body['id'] ?? null)
            || ! preg_match('/^[A-Z0-9]{1,36}$/D', $body['id']) || ! is_array($body['links'] ?? null)) {
            throw new RuntimeException('PayPal Sandbox returned an invalid order response.');
        }
        foreach ($body['links'] as $link) {
            if (! is_array($link) || ! in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                continue;
            }
            $url = $link['href'] ?? null;
            if (($link['method'] ?? null) !== 'GET' || ! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_HOST) !== 'www.sandbox.paypal.com'
                || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
                || str_contains($url, '\\')) {
                throw new RuntimeException('PayPal Sandbox returned an invalid approval URL.');
            }

            return ['id' => $body['id'], 'approval_url' => $url];
        }

        throw new RuntimeException('PayPal Sandbox returned an order without an approval URL.');
    }

    /** @return array{id: string, status: string, intent: string, purchase_units: array} */
    public function getOrder(string $orderId): array
    {
        if (! preg_match('/^[A-Z0-9]{1,36}$/D', $orderId)) {
            throw new InvalidArgumentException('A valid PayPal Order ID is required.');
        }

        $token = $this->accessToken();
        try {
            $response = Http::acceptJson()->withToken($token)
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->get('https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$orderId);
        } catch (ConnectionException) {
            throw new RuntimeException('PayPal Sandbox order lookup connection failed.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('PayPal Sandbox order lookup failed.');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['id'] ?? null) !== $orderId
            || ! in_array($body['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED'], true)
            || ! in_array($body['intent'] ?? null, ['CAPTURE', 'AUTHORIZE'], true)
            || ! is_array($body['purchase_units'] ?? null) || $body['purchase_units'] === []
            || ! array_is_list($body['purchase_units'])) {
            throw new RuntimeException('PayPal Sandbox returned invalid order details.');
        }
        foreach ($body['purchase_units'] as $unit) {
            if (! is_array($unit) || ! $this->validOrderAmount($unit['amount'] ?? null)) {
                throw new RuntimeException('PayPal Sandbox returned invalid order details.');
            }
            if (! array_key_exists('payments', $unit)) {
                continue;
            }
            if (! is_array($unit['payments'])) {
                throw new RuntimeException('PayPal Sandbox returned invalid order details.');
            }
            if (! array_key_exists('captures', $unit['payments'])) {
                continue;
            }
            $captures = $unit['payments']['captures'];
            if (! is_array($captures) || ! array_is_list($captures)) {
                throw new RuntimeException('PayPal Sandbox returned invalid order details.');
            }
            foreach ($captures as $capture) {
                if (! is_array($capture) || ! is_string($capture['id'] ?? null)
                    || ! preg_match('/^[A-Z0-9]{1,36}$/D', $capture['id'])
                    || ! is_string($capture['status'] ?? null) || trim($capture['status']) === ''
                    || ! $this->validOrderAmount($capture['amount'] ?? null)) {
                    throw new RuntimeException('PayPal Sandbox returned invalid order details.');
                }
            }
        }

        // Provider data only: APPROVED is not proof of capture or successful payment.
        return ['id' => $body['id'], 'status' => $body['status'], 'intent' => $body['intent'],
            'purchase_units' => $body['purchase_units']];
    }

    /** @return array{id: string, status: string, purchase_units: array} */
    public function captureOrder(string $orderId, string $requestId): array
    {
        if (! preg_match('/^[A-Z0-9]{1,36}$/D', $orderId)) {
            throw new InvalidArgumentException('A valid PayPal Order ID is required.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{1,38}$/D', $requestId)) {
            throw new InvalidArgumentException('A stable PayPal request ID of at most 38 characters is required.');
        }

        $token = $this->accessToken();
        try {
            $response = Http::acceptJson()->withToken($token)
                ->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation',
                    'Content-Type' => 'application/json'])
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->send('POST', 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$orderId.'/capture', ['body' => '{}']);
        } catch (ConnectionException) {
            // Capture may have occurred: never replace the request ID on a retry.
            throw new RuntimeException('PayPal Sandbox capture connection failed; retry only with the same request ID.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('PayPal Sandbox capture failed; retry only with the same request ID.');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['id'] ?? null) !== $orderId
            || ! in_array($body['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED'], true)
            || ! is_array($body['purchase_units'] ?? null) || $body['purchase_units'] === []
            || ! array_is_list($body['purchase_units'])) {
            throw new RuntimeException('PayPal Sandbox returned invalid capture details.');
        }
        foreach ($body['purchase_units'] as $unit) {
            if (! is_array($unit) || ! is_array($unit['payments'] ?? null)
                || ! is_array($unit['payments']['captures'] ?? null) || $unit['payments']['captures'] === []
                || ! array_is_list($unit['payments']['captures'])
                || (array_key_exists('amount', $unit) && ! $this->validOrderAmount($unit['amount']))) {
                throw new RuntimeException('PayPal Sandbox returned invalid capture details.');
            }
            foreach ($unit['payments']['captures'] as $capture) {
                if (! is_array($capture) || ! is_string($capture['id'] ?? null)
                    || ! preg_match('/^[A-Z0-9]{1,36}$/D', $capture['id'])
                    || ! is_string($capture['status'] ?? null) || trim($capture['status']) === ''
                    || ! $this->validOrderAmount($capture['amount'] ?? null)) {
                    throw new RuntimeException('PayPal Sandbox returned invalid capture details.');
                }
            }
        }

        // Return provider statuses verbatim; neither HTTP success nor order COMPLETED proves a completed capture.
        return ['id' => $body['id'], 'status' => $body['status'], 'purchase_units' => $body['purchase_units']];
    }

    private function validOrderAmount(mixed $amount): bool
    {
        return is_array($amount) && is_string($amount['value'] ?? null)
            && preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount['value']) === 1
            && is_string($amount['currency_code'] ?? null)
            && preg_match('/^[A-Z]{3}$/D', $amount['currency_code']) === 1;
    }
}
