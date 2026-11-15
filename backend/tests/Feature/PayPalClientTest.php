<?php

namespace Tests\Feature;

use App\Payments\PayPalClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PayPalClientTest extends TestCase
{
    private const TOKEN_URL = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.paypal.enabled' => true, 'payment.paypal.client_id' => 'sandbox-client',
            'payment.paypal.client_secret' => 'sandbox-secret', 'payment.paypal.merchant_id' => 'sandbox-merchant',
            'cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
        Log::spy();
    }

    public function test_it_requests_a_token_using_sandbox_basic_auth_and_form_data(): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token('test-token'))]);

        $this->assertSame('test-token', app(PayPalClient::class)->accessToken());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::TOKEN_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox-client:sandbox-secret'))
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request->data() === ['grant_type' => 'client_credentials']);
        Http::assertSentCount(1);
        $this->assertNoLogs();
    }

    public function test_it_reuses_the_cached_token_across_client_instances(): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token('cached-token'))]);
        $this->assertSame('cached-token', (new PayPalClient)->accessToken());
        $this->travel(60)->seconds();
        $this->assertSame('cached-token', (new PayPalClient)->accessToken());
        Http::assertSentCount(1);
    }

    public function test_it_refreshes_an_expired_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push($this->token('first-token', 120))->push($this->token('new-token', 120))]);
        $client = new PayPalClient;
        $this->assertSame('first-token', $client->accessToken());
        $this->travel(121)->seconds();
        $this->assertSame('new-token', $client->accessToken());
        Http::assertSentCount(2);
    }

    public function test_it_refreshes_before_provider_expiry_and_does_not_cache_short_lived_tokens(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push($this->token('first-token', 120))->push($this->token('short-token', 20))
            ->push($this->token('replacement-token'))]);
        $client = new PayPalClient;
        $client->accessToken();
        $this->travel(91)->seconds();
        $this->assertSame('short-token', $client->accessToken());
        $this->assertSame('replacement-token', $client->accessToken());
        Http::assertSentCount(3);
    }

    public function test_credential_rotation_does_not_reuse_an_old_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push($this->token('old-token'))->push($this->token('new-token'))]);
        $client = new PayPalClient;
        $client->accessToken();
        config(['payment.paypal.client_secret' => 'rotated-secret']);
        $this->assertSame('new-token', $client->accessToken());
        Http::assertSentCount(2);
    }

    public function test_disabled_or_missing_credentials_prevent_requests_even_with_a_cached_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token('cached-token'))]);
        $client = new PayPalClient;
        $client->accessToken();
        config(['payment.paypal.enabled' => false]);
        $this->assertSafeFailure('PayPal Sandbox is disabled.');
        config(['payment.paypal.enabled' => true, 'payment.paypal.client_secret' => '']);
        $this->assertSafeFailure('PayPal Sandbox credentials are not configured.');
        Http::assertSentCount(1);
    }

    public function test_authentication_errors_are_sanitized_and_not_cached(): void
    {
        $sequence = Http::sequence();
        foreach ([400, 401, 403] as $status) {
            $sequence->push(['error_description' => 'sandbox-secret test-token'], $status)
                ->push($this->token('recovered-token'));
        }
        Http::fake([self::TOKEN_URL => $sequence]);
        foreach ([400, 401, 403] as $status) {
            $this->assertSafeFailure(in_array($status, [401, 403], true)
                ? 'PayPal Sandbox authentication was rejected.' : 'PayPal Sandbox authentication failed.');
            $this->assertSame('recovered-token', (new PayPalClient)->accessToken());
            Cache::flush();
        }
        $this->assertNoLogs();
    }

    public function test_server_errors_are_sanitized_and_can_be_retried(): void
    {
        $sequence = Http::sequence();
        foreach ([500, 503] as $status) {
            $sequence->push('sandbox-secret test-token', $status)->push($this->token('recovered-token'));
        }
        Http::fake([self::TOKEN_URL => $sequence]);
        foreach ([500, 503] as $status) {
            $this->assertSafeFailure('PayPal Sandbox authentication is temporarily unavailable.');
            $this->assertSame('recovered-token', (new PayPalClient)->accessToken());
            Cache::flush();
        }
        $this->assertNoLogs();
    }

    public function test_timeout_is_sanitized_without_chaining_the_transport_exception(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('cURL error 28: sandbox-secret test-token');
            }

            return Http::response($this->token('recovered-token'));
        });
        $this->assertSafeFailure('PayPal Sandbox authentication connection failed.');
        $this->assertSame('recovered-token', (new PayPalClient)->accessToken());
        $this->assertNoLogs();
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(self::TOKEN_URL, $request->url());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);

            return Http::response('', 302, ['Location' => 'https://api-m.paypal.com/v1/oauth2/token']);
        });
        $this->assertSafeFailure('PayPal Sandbox authentication failed.');
        Http::assertSentCount(1);
    }

    public function test_invalid_token_responses_are_not_cached(): void
    {
        $invalid = [[], $this->token('', 120), $this->token('test-token', 0),
            array_replace($this->token(), ['expires_in' => '120']),
            array_replace($this->token(), ['token_type' => 'unexpected'])];
        $sequence = Http::sequence();
        foreach ($invalid as $body) {
            $sequence->push($body);
        }
        $sequence->push($this->token('valid-token'));
        Http::fake([self::TOKEN_URL => $sequence]);
        foreach ($invalid as $body) {
            $this->assertSafeFailure('PayPal Sandbox returned an invalid authentication response.');
        }
        $this->assertSame('valid-token', (new PayPalClient)->accessToken());
    }

    public function test_it_creates_a_sandbox_order_with_an_exact_eur_string_and_stable_request_id(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response($this->orderResponse(), 201),
        ]);
        $client = new PayPalClient;
        $expected = ['id' => '5O190127TN364715T', 'approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'];
        $this->assertSame($expected, $client->createOrder('9999999999.99', 'stable-order-123', 'http://localhost:5173/return', 'http://localhost:5173/cancel'));
        $this->assertSame($expected, $client->createOrder('9999999999.99', 'stable-order-123', 'http://localhost:5173/return', 'http://localhost:5173/cancel'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('PayPal-Request-Id', 'stable-order-123')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'intent' => 'CAPTURE',
                'purchase_units' => [['amount' => ['currency_code' => 'EUR', 'value' => '9999999999.99']]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => 'http://localhost:5173/return', 'cancel_url' => 'http://localhost:5173/cancel',
                    'shipping_preference' => 'NO_SHIPPING',
                ]]],
            ]);
        Http::assertSentCount(3); // One OAuth request, two creates using the same key.
        foreach (Http::recorded() as [$request]) {
            if ($request->url() !== self::TOKEN_URL) {
                $this->assertSame('stable-order-123', $request->header('PayPal-Request-Id')[0]);
            }
        }
        $this->assertNoLogs();
    }

    public function test_it_accepts_the_approve_link_relation(): void
    {
        $body = $this->orderResponse();
        $body['links'][0]['rel'] = 'approve';
        $this->fakeOrders(Http::response($body, 200));
        $this->assertSame('5O190127TN364715T', $this->createOrder()['id']);
    }

    public function test_invalid_order_inputs_are_rejected_before_oauth_or_api_calls(): void
    {
        Http::fake();
        foreach (['0.00', '-1.00', '1', '1.1', '1.001', '01.00', '1e2', '1,00', '10000000000.00'] as $amount) {
            try {
                $this->createOrder($amount);
                $this->fail('Invalid amount was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        foreach (['', str_repeat('a', 39), "bad\r\nheader"] as $key) {
            try {
                $this->createOrder(requestId: $key);
                $this->fail('Invalid request ID was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        foreach (['/relative', 'javascript:alert(1)', 'https://user:password@example.test/return'] as $url) {
            foreach ([true, false] as $isReturn) {
                try {
                    (new PayPalClient)->createOrder('0.01', 'stable-id', $isReturn ? $url : 'https://shop.test/return', $isReturn ? 'https://shop.test/cancel' : $url);
                    $this->fail('Invalid callback URL was accepted.');
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_order_api_errors_are_sanitized(): void
    {
        $sequence = Http::sequence();
        foreach ([400, 401, 403, 422, 429, 500, 503] as $status) {
            $sequence->push(['message' => 'sandbox-secret test-token'], $status);
        }
        $this->fakeOrders($sequence);
        foreach ([400, 401, 403, 422, 429, 500, 503] as $status) {
            $this->assertOrderFailure('PayPal Sandbox order creation failed; retry only with the same request ID.');
        }
        $this->assertNoLogs();
    }

    public function test_order_timeout_is_sanitized_and_uses_no_redirects(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => function (Request $request, array $options) {
                $this->assertFalse($options['allow_redirects']);
                $this->assertSame(5, $options['connect_timeout']);
                $this->assertSame(10, $options['timeout']);
                throw new ConnectionException('cURL error 28: sandbox-secret test-token');
            },
        ]);
        $this->assertOrderFailure('PayPal Sandbox order creation connection failed; retry with the same request ID.');
        $this->assertNoLogs();
    }

    public function test_invalid_order_responses_are_rejected(): void
    {
        $sequence = Http::sequence();
        foreach ([null, 'not json', [], ['id' => 123, 'links' => []], ['id' => '', 'links' => []],
            ['id' => 'bad/id', 'links' => []], ['id' => 'ORDER123', 'links' => 'invalid']] as $body) {
            $sequence->push($body);
        }
        $this->fakeOrders($sequence);
        for ($i = 0; $i < 7; $i++) {
            $this->assertOrderFailure('PayPal Sandbox returned an invalid order response.');
        }
    }

    public function test_missing_approval_link_is_rejected(): void
    {
        $this->fakeOrders(Http::response(['id' => 'ORDER123', 'links' => [null, ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123']]]));
        $this->assertOrderFailure('PayPal Sandbox returned an order without an approval URL.');
    }

    public function test_unsafe_approval_urls_are_rejected(): void
    {
        $urls = ['https://www.paypal.com/checkoutnow', 'http://www.sandbox.paypal.com/checkoutnow',
            'https://www.sandbox.paypal.com.evil.test/checkoutnow', 'https://evil.test/?next=www.sandbox.paypal.com',
            'https://www.sandbox.paypal.com@evil.test/checkoutnow', 'https://user@www.sandbox.paypal.com/checkoutnow',
            'https://www.sandbox.paypal.com:444/checkoutnow', '//www.sandbox.paypal.com/checkoutnow',
            'javascript:alert(1)', '', null, ['invalid']];
        $sequence = Http::sequence();
        foreach ($urls as $url) {
            $body = $this->orderResponse();
            $body['links'][0]['href'] = $url;
            $sequence->push($body);
        }
        $body = $this->orderResponse();
        $body['links'][0]['method'] = 'POST';
        $sequence->push($body);
        $this->fakeOrders($sequence);
        for ($i = 0; $i < count($urls) + 1; $i++) {
            $this->assertOrderFailure('PayPal Sandbox returned an invalid approval URL.');
        }
    }

    public function test_order_http_redirect_is_not_followed(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => function (Request $request, array $options) {
                $this->assertFalse($options['allow_redirects']);

                return Http::response('', 302, ['Location' => 'https://api-m.paypal.com/v2/checkout/orders']);
            },
        ]);
        $this->assertOrderFailure('PayPal Sandbox order creation failed; retry only with the same request ID.');
        Http::assertSentCount(2);
    }

    public function test_get_order_preserves_created_and_approved_provider_data_without_claiming_payment_success(): void
    {
        $sequence = Http::sequence();
        foreach (['CREATED', 'APPROVED'] as $status) {
            $sequence->push($this->orderDetails($status));
        }
        $this->fakeOrderLookup($sequence);
        foreach (['CREATED', 'APPROVED'] as $status) {
            $result = (new PayPalClient)->getOrder('5O190127TN364715T');
            $this->assertSame($this->orderDetails($status), $result);
            $this->assertSame($status, $result['status']);
            $this->assertSame('CAPTURE', $result['intent']);
            $this->assertSame(['value' => '12.34', 'currency_code' => 'EUR'], $result['purchase_units'][0]['amount']);
            $this->assertArrayNotHasKey('paid', $result);
            $this->assertArrayNotHasKey('succeeded', $result);
            $this->assertArrayNotHasKey('payments', $result['purchase_units'][0]);
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->body() === '');
        Http::assertSentCount(3);
        $this->assertNoLogs();
    }

    public function test_get_completed_order_preserves_capture_details_and_other_purchase_unit_fields(): void
    {
        $body = $this->orderDetails('COMPLETED');
        $body['purchase_units'][0]['payments'] = ['captures' => [[
            'id' => '3C679366HH908993F', 'status' => 'COMPLETED',
            'amount' => ['value' => '12.34', 'currency_code' => 'EUR'], 'final_capture' => true,
        ]]];
        $body['purchase_units'][0]['payee'] = ['merchant_id' => 'sandbox-merchant'];
        $response = $body + ['payer' => ['email_address' => 'buyer@example.test']];
        $this->fakeOrderLookup(Http::response($response));
        $this->assertSame($body, (new PayPalClient)->getOrder('5O190127TN364715T'));
        $this->assertNoLogs();
    }

    public function test_invalid_lookup_order_ids_are_rejected_before_any_http_request(): void
    {
        Http::fake();
        foreach (['', '../orders', 'ORDER/OTHER', 'ORDER?query=1', 'ORDER#fragment', 'ORDER%2FOTHER',
            'https://api-m.paypal.com', 'lowercase', ' ORDER123', "ORDER123\n", str_repeat('A', 37)] as $id) {
            try {
                (new PayPalClient)->getOrder($id);
                $this->fail('Invalid Order ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A valid PayPal Order ID is required.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_get_order_http_errors_are_sanitized(): void
    {
        $sequence = Http::sequence();
        foreach ([401, 403, 404, 500, 503] as $status) {
            $sequence->push(['message' => 'sandbox-secret test-token'], $status);
        }
        $this->fakeOrderLookup($sequence);
        foreach ([401, 403, 404, 500, 503] as $status) {
            $this->assertLookupFailure('PayPal Sandbox order lookup failed.');
        }
        $this->assertNoLogs();
    }

    public function test_get_order_timeout_is_sanitized(): void
    {
        $this->fakeOrderLookup(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            throw new ConnectionException('cURL error 28: sandbox-secret test-token');
        });
        $this->assertLookupFailure('PayPal Sandbox order lookup connection failed.');
        $this->assertNoLogs();
    }

    public function test_get_order_does_not_follow_redirects_to_live_api(): void
    {
        $this->fakeOrderLookup(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', 302, ['Location' => 'https://api-m.paypal.com/v2/checkout/orders/5O190127TN364715T']);
        });
        $this->assertLookupFailure('PayPal Sandbox order lookup failed.');
        Http::assertSentCount(2);
    }

    public function test_get_order_rejects_invalid_json_missing_fields_and_mismatched_identity(): void
    {
        $valid = $this->orderDetails();
        $invalid = ['not JSON', null, [], '"scalar"'];
        foreach (['id', 'status', 'intent', 'purchase_units'] as $field) {
            $body = $valid;
            unset($body[$field]);
            $invalid[] = $body;
        }
        foreach (['id' => 'OTHER123', 'status' => 'UNKNOWN', 'intent' => 'INVALID', 'purchase_units' => []] as $field => $value) {
            $invalid[] = array_replace($valid, [$field => $value]);
        }
        foreach ([null, ['value' => 12.34, 'currency_code' => 'EUR'], ['value' => '12.34'],
            ['value' => '1e2', 'currency_code' => 'EUR'], ['value' => '12.34', 'currency_code' => 'invalid']] as $amount) {
            $body = $valid;
            $body['purchase_units'][0]['amount'] = $amount;
            $invalid[] = $body;
        }
        $body = $valid;
        $body['purchase_units'] = ['not-a-list' => $valid['purchase_units'][0]];
        $invalid[] = $body;
        $body = $valid;
        $body['purchase_units'] = [null];
        $invalid[] = $body;
        $sequence = Http::sequence();
        foreach ($invalid as $body) {
            $sequence->push($body);
        }
        $this->fakeOrderLookup($sequence);
        foreach ($invalid as $body) {
            $this->assertLookupFailure('PayPal Sandbox returned invalid order details.');
        }
        $this->assertNoLogs();
    }

    public function test_get_order_rejects_malformed_capture_data(): void
    {
        $sequence = Http::sequence();
        $invalidPayments = [null, ['captures' => null], ['captures' => 'invalid'], ['captures' => [null]],
            ['captures' => [['id' => 'CAPTURE123', 'status' => 'COMPLETED']]],
            ['captures' => [['id' => 'CAPTURE123', 'status' => '', 'amount' => ['value' => '12.34', 'currency_code' => 'EUR']]]]];
        foreach ($invalidPayments as $payments) {
            $body = $this->orderDetails('COMPLETED');
            $body['purchase_units'][0]['payments'] = $payments;
            $sequence->push($body);
        }
        $this->fakeOrderLookup($sequence);
        foreach ($invalidPayments as $payments) {
            $this->assertLookupFailure('PayPal Sandbox returned invalid order details.');
        }
    }

    public function test_capture_order_sends_sandbox_post_with_oauth_stable_request_id_and_full_response_preference(): void
    {
        $body = $this->captureDetails();
        $this->fakeCapture(Http::response($body, 201));
        $this->assertSame($body, (new PayPalClient)->captureOrder('5O190127TN364715T', 'capture-request-123'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('PayPal-Request-Id', 'capture-request-123')
            && $request->hasHeader('Prefer', 'return=representation')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('Accept', 'application/json')
            && $request->body() === '{}');
        Http::assertSentCount(2);
        $this->assertNoLogs();
    }

    public function test_capture_preserves_completed_and_pending_statuses_without_claiming_payment_success(): void
    {
        $sequence = Http::sequence()->push($this->captureDetails('COMPLETED'), 201)
            ->push($this->captureDetails('PENDING'), 200);
        $this->fakeCapture($sequence);
        foreach (['COMPLETED', 'PENDING'] as $status) {
            $result = (new PayPalClient)->captureOrder('5O190127TN364715T', 'capture-request-123');
            $this->assertSame('COMPLETED', $result['status']);
            $capture = $result['purchase_units'][0]['payments']['captures'][0];
            $this->assertSame('3C679366HH908993F', $capture['id']);
            $this->assertSame($status, $capture['status']);
            $this->assertSame(['value' => '12.34', 'currency_code' => 'EUR'], $capture['amount']);
            $this->assertArrayNotHasKey('paid', $result);
            $this->assertArrayNotHasKey('succeeded', $result);
        }
    }

    public function test_capture_rejects_invalid_order_ids_and_request_ids_before_oauth(): void
    {
        Http::fake();
        foreach (['', '../orders', 'ORDER/capture', 'ORDER?query=1', 'ORDER#fragment', 'ORDER%2FOTHER',
            'https://api-m.paypal.com', 'lowercase', "ORDER123\n", str_repeat('A', 37)] as $id) {
            try {
                (new PayPalClient)->captureOrder($id, 'capture-request-123');
                $this->fail('Invalid Order ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A valid PayPal Order ID is required.', $exception->getMessage());
            }
        }
        foreach (['', str_repeat('a', 39), "bad\r\nheader", 'contains space'] as $key) {
            try {
                (new PayPalClient)->captureOrder('5O190127TN364715T', $key);
                $this->fail('Invalid capture request ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A stable PayPal request ID of at most 38 characters is required.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_capture_http_errors_are_sanitized(): void
    {
        $statuses = [400, 401, 403, 404, 409, 422, 429, 500, 503];
        $sequence = Http::sequence();
        foreach ($statuses as $status) {
            $sequence->push(['message' => 'sandbox-secret test-token'], $status);
        }
        $this->fakeCapture($sequence);
        foreach ($statuses as $status) {
            $this->assertCaptureFailure('PayPal Sandbox capture failed; retry only with the same request ID.');
        }
        $this->assertNoLogs();
    }

    public function test_capture_timeout_can_be_retried_with_the_same_request_id(): void
    {
        $calls = 0;
        $this->fakeCapture(function (Request $request, array $options) use (&$calls) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(['capture-request-123'], $request->header('PayPal-Request-Id'));
            if ($calls++ === 0) {
                throw new ConnectionException('cURL error 28: sandbox-secret test-token');
            }

            return Http::response($this->captureDetails(), 200);
        });
        $this->assertCaptureFailure('PayPal Sandbox capture connection failed; retry only with the same request ID.');
        $this->assertSame($this->captureDetails(), (new PayPalClient)->captureOrder('5O190127TN364715T', 'capture-request-123'));
        $this->assertSame(2, $calls);
        $this->assertNoLogs();
    }

    public function test_repeated_capture_calls_preserve_the_same_request_id_and_result(): void
    {
        $this->fakeCapture(Http::sequence()->push($this->captureDetails(), 201)->push($this->captureDetails(), 200));
        $client = new PayPalClient;
        $first = $client->captureOrder('5O190127TN364715T', 'capture-request-123');
        $this->assertSame($first, $client->captureOrder('5O190127TN364715T', 'capture-request-123'));
        foreach (Http::recorded() as [$request]) {
            if ($request->url() !== self::TOKEN_URL) {
                $this->assertSame(['capture-request-123'], $request->header('PayPal-Request-Id'));
            }
        }
        Http::assertSentCount(3);
    }

    public function test_capture_rejects_invalid_json_and_missing_order_fields(): void
    {
        $invalid = ['not JSON', null, [], '"scalar"'];
        foreach (['id', 'status', 'purchase_units'] as $field) {
            $body = $this->captureDetails();
            unset($body[$field]);
            $invalid[] = $body;
        }
        foreach (['id' => 'OTHER123', 'status' => 'UNKNOWN', 'purchase_units' => []] as $field => $value) {
            $invalid[] = array_replace($this->captureDetails(), [$field => $value]);
        }
        $body = $this->captureDetails();
        $body['purchase_units'] = ['not-a-list' => $body['purchase_units'][0]];
        $invalid[] = $body;
        $body['purchase_units'] = [null];
        $invalid[] = $body;
        $sequence = Http::sequence();
        foreach ($invalid as $body) {
            $sequence->push($body);
        }
        $this->fakeCapture($sequence);
        foreach ($invalid as $body) {
            $this->assertCaptureFailure('PayPal Sandbox returned invalid capture details.');
        }
    }

    public function test_capture_rejects_missing_capture_identity_status_amount_or_currency(): void
    {
        $validCapture = $this->captureDetails()['purchase_units'][0]['payments']['captures'][0];
        $invalid = [null, [], ['id' => 'CAPTURE123'], array_replace($validCapture, ['id' => 'bad/id']),
            array_replace($validCapture, ['status' => '']),
            array_replace($validCapture, ['amount' => ['value' => '12.34']]),
            array_replace($validCapture, ['amount' => ['currency_code' => 'EUR']]),
            array_replace($validCapture, ['amount' => ['value' => 12.34, 'currency_code' => 'EUR']])];
        foreach (['id', 'status', 'amount'] as $field) {
            $capture = $validCapture;
            unset($capture[$field]);
            $invalid[] = $capture;
        }
        $sequence = Http::sequence();
        foreach ($invalid as $capture) {
            $body = $this->captureDetails();
            $body['purchase_units'][0]['payments']['captures'] = [$capture];
            $sequence->push($body);
        }
        $this->fakeCapture($sequence);
        foreach ($invalid as $capture) {
            $this->assertCaptureFailure('PayPal Sandbox returned invalid capture details.');
        }
    }

    public function test_capture_rejects_absent_or_malformed_capture_collections_even_when_order_is_completed(): void
    {
        $payments = [null, [], ['captures' => null], ['captures' => []], ['captures' => 'invalid'],
            ['captures' => ['not-a-list' => $this->captureDetails()['purchase_units'][0]['payments']['captures'][0]]]];
        $sequence = Http::sequence();
        foreach ($payments as $payment) {
            $body = $this->captureDetails();
            $body['purchase_units'][0]['payments'] = $payment;
            $sequence->push($body, 200);
        }
        $body = $this->captureDetails();
        unset($body['purchase_units'][0]['payments']);
        $sequence->push($body, 200);
        $this->fakeCapture($sequence);
        for ($i = 0; $i < count($payments) + 1; $i++) {
            $this->assertCaptureFailure('PayPal Sandbox returned invalid capture details.');
        }
    }

    public function test_capture_does_not_follow_redirects_to_live_api(): void
    {
        $this->fakeCapture(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', 302, ['Location' => 'https://api-m.paypal.com/v2/checkout/orders/5O190127TN364715T/capture']);
        });
        $this->assertCaptureFailure('PayPal Sandbox capture failed; retry only with the same request ID.');
        Http::assertSentCount(2);
    }

    private function captureDetails(string $status = 'COMPLETED'): array
    {
        return ['id' => '5O190127TN364715T', 'status' => 'COMPLETED', 'purchase_units' => [
            ['reference_id' => 'default', 'payments' => ['captures' => [[
                'id' => '3C679366HH908993F', 'status' => $status,
                'amount' => ['value' => '12.34', 'currency_code' => 'EUR'], 'final_capture' => true,
            ]]]],
        ]];
    }

    private function fakeCapture(mixed $response): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture' => $response]);
    }

    private function assertCaptureFailure(string $message): void
    {
        try {
            (new PayPalClient)->captureOrder('5O190127TN364715T', 'capture-request-123');
            $this->fail('Expected capture to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('test-token', (string) $exception);
        }
    }

    private function orderDetails(string $status = 'CREATED'): array
    {
        return ['id' => '5O190127TN364715T', 'status' => $status, 'intent' => 'CAPTURE',
            'purchase_units' => [['reference_id' => 'default', 'amount' => ['value' => '12.34', 'currency_code' => 'EUR']]]];
    }

    private function fakeOrderLookup(mixed $response): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T' => $response]);
    }

    private function assertLookupFailure(string $message): void
    {
        try {
            (new PayPalClient)->getOrder('5O190127TN364715T');
            $this->fail('Expected order lookup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('test-token', (string) $exception);
        }
    }

    private function createOrder(string $amount = '0.01', string $requestId = 'stable-order-123'): array
    {
        return (new PayPalClient)->createOrder($amount, $requestId, 'https://shop.test/return', 'https://shop.test/cancel');
    }

    private function orderResponse(): array
    {
        return ['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
            ['rel' => 'payer-action', 'method' => 'GET', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'],
        ]];
    }

    private function fakeOrders(mixed $response): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->token()),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => $response]);
    }

    private function assertOrderFailure(string $message): void
    {
        try {
            $this->createOrder();
            $this->fail('Expected order creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('test-token', (string) $exception);
        }
    }

    private function token(string $token = 'test-token', int $expiresIn = 120): array
    {
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn];
    }

    private function assertSafeFailure(string $message): void
    {
        try {
            (new PayPalClient)->accessToken();
            $this->fail('Expected an authentication failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('test-token', (string) $exception);
        }
    }

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
