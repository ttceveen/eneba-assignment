<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\PaymentGatewayInterface;
use App\Payments\PaymentResult;
use App\Payments\PayPalClient;
use App\Payments\PayPalGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PayPalGatewayTest extends TestCase
{
    private const RETURN_URL = 'http://localhost:5173/orders/123?paypal=return';

    private const CANCEL_URL = 'http://localhost:5173/orders/123?paypal=cancel';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();
    }

    public function test_create_maps_the_client_order_to_a_pending_payment_result_without_mutating_payment(): void
    {
        $payment = $this->payment();
        $before = $payment->getAttributes();
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('createOrder')->once()
            ->with('12.34', 'paypal-create-42', self::RETURN_URL, self::CANCEL_URL)
            ->andReturn($this->order());
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);

        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $result = $gateway->create($payment);
        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertSame('pending', $result->status);
        $this->assertSame('5O190127TN364715T', $result->externalId);
        $this->assertSame($this->order()['approval_url'], $result->redirectUrl);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->errorMessage);
        $this->assertSame($before, $payment->getAttributes());
        $this->assertFalse($payment->isDirty());
        $this->assertSame([], $payment->getRelations());
        Http::assertNothingSent();
        $this->assertNoLogs();
    }

    public function test_request_id_is_stable_across_retries_and_instances_and_distinct_for_other_payments(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('createOrder')->twice()
            ->with('12.34', 'paypal-create-42', self::RETURN_URL, self::CANCEL_URL)->andReturn($this->order());
        $client->shouldReceive('createOrder')->once()
            ->with('12.34', 'paypal-create-43', self::RETURN_URL, self::CANCEL_URL)->andReturn($this->order());

        $first = (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($this->payment());
        $retry = (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($this->payment());
        $this->assertEquals($first, $retry);
        (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($this->payment(['id' => 43]));
    }

    public function test_decimal_amounts_are_passed_as_strings_without_float_conversion(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        foreach (['0.01', '9999999999.99'] as $amount) {
            $client->shouldReceive('createOrder')->once()
                ->with($amount, 'paypal-create-42', self::RETURN_URL, self::CANCEL_URL)->andReturn($this->order());
            (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($this->payment(['amount' => $amount]));
        }
    }

    public function test_non_eur_currency_is_rejected_before_the_client_is_called(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldNotReceive('createOrder');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PayPal Sandbox supports only EUR payments.');
        (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($this->payment(['currency' => 'USD']));
    }

    public function test_unsaved_or_invalid_payment_ids_are_rejected_before_the_client_is_called(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldNotReceive('createOrder');
        $unsaved = $this->payment();
        $unsaved->exists = false;
        foreach ([$unsaved, $this->payment(['id' => null]), $this->payment(['id' => 0]), $this->payment(['id' => -1])] as $payment) {
            try {
                (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->create($payment);
                $this->fail('Invalid payment ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A saved payment with a valid ID is required.', $exception->getMessage());
            }
        }
    }

    public function test_client_error_is_sanitized_and_retry_uses_the_same_payment_and_request_id(): void
    {
        $payment = $this->payment();
        $before = $payment->getAttributes();
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('createOrder')->once()->ordered()
            ->with('12.34', 'paypal-create-42', self::RETURN_URL, self::CANCEL_URL)
            ->andThrow(new RuntimeException('sandbox-secret access-token-sensitive provider error'));
        $client->shouldReceive('createOrder')->once()->ordered()
            ->with('12.34', 'paypal-create-42', self::RETURN_URL, self::CANCEL_URL)->andReturn($this->order());
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);
        try {
            $gateway->create($payment);
            $this->fail('Expected a client failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PayPal Sandbox payment initiation is unconfirmed; retry the same payment.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('access-token-sensitive', (string) $exception);
            $this->assertSame($before, $payment->getAttributes());
        }
        $this->assertSame('pending', $gateway->create($payment)->status);
        $this->assertSame($before, $payment->getAttributes());
        Http::assertNothingSent();
        $this->assertNoLogs();
    }

    public function test_confirm_captures_approved_order_with_stable_id_without_mutating_payment(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('getOrder')->once()->with('5O190127TN364715T')->andReturn($this->details('APPROVED'));
        $client->shouldReceive('captureOrder')->once()->with('5O190127TN364715T', 'paypal-capture-42')->andReturn($this->details('COMPLETED', 'COMPLETED'));
        $payment = $this->payment(['external_id' => '5O190127TN364715T']);
        $before = $payment->getAttributes();
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);
        $result = $gateway->confirm($payment, ['result' => 'failure', 'amount' => '0.01']);
        $this->assertSame('succeeded', $result->status);
        $this->assertSame('5O190127TN364715T', $result->externalId);
        $this->assertSame('CAPTURE123', $result->captureId);
        $this->assertSame($before, $payment->getAttributes());
        $this->assertFalse($gateway->supportsSimulation());
        $this->assertNoLogs();
    }

    public function test_repeated_confirm_reuses_existing_completed_capture(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('getOrder')->twice()->andReturn($this->details('APPROVED'), $this->details('COMPLETED', 'COMPLETED'));
        $client->shouldReceive('captureOrder')->once()->with('5O190127TN364715T', 'paypal-capture-42')->andReturn($this->details('COMPLETED', 'COMPLETED'));
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);
        $payment = $this->payment(['external_id' => '5O190127TN364715T']);
        $this->assertEquals($gateway->confirm($payment), $gateway->confirm($payment));
    }

    public function test_pending_capture_does_not_succeed_or_trigger_another_capture(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('getOrder')->twice()->andReturn($this->details('APPROVED'), $this->details('COMPLETED', 'PENDING'));
        $client->shouldReceive('captureOrder')->once()->andReturn($this->details('COMPLETED', 'PENDING'));
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);
        foreach ([1, 2] as $attempt) {
            $result = $gateway->confirm($this->payment(['external_id' => '5O190127TN364715T']));
            $this->assertSame('pending', $result->status);
            $this->assertSame('CAPTURE123', $result->captureId);
        }
    }

    public function test_confirmation_checks_existing_capture_amount_currency_and_required_fields(): void
    {
        $invalid = [];
        foreach (['id', 'status', 'amount'] as $field) {
            $body = $this->details('COMPLETED', 'COMPLETED');
            unset($body['purchase_units'][0]['payments']['captures'][0][$field]);
            $invalid[] = $body;
        }
        foreach ([['value' => '12.35', 'currency_code' => 'EUR'], ['value' => '12.34', 'currency_code' => 'USD']] as $amount) {
            $body = $this->details('COMPLETED', 'COMPLETED');
            $body['purchase_units'][0]['payments']['captures'][0]['amount'] = $amount;
            $invalid[] = $body;
        }
        $body = $this->details('COMPLETED', 'COMPLETED');
        $body['id'] = 'OTHER123';
        $invalid[] = $body;
        foreach ($invalid as $body) {
            $client = Mockery::mock(PayPalClient::class);
            $client->shouldReceive('getOrder')->once()->andReturn($body);
            $client->shouldNotReceive('captureOrder');
            $this->assertConfirmFailure($client);
        }
    }

    public function test_capture_response_amount_currency_identity_and_missing_id_are_checked(): void
    {
        $invalid = [];
        foreach (['id' => '', 'amount' => ['value' => '0.01', 'currency_code' => 'EUR'],
            'status' => ''] as $field => $value) {
            $body = $this->details('COMPLETED', 'COMPLETED');
            $body['purchase_units'][0]['payments']['captures'][0][$field] = $value;
            $invalid[] = $body;
        }
        $body = $this->details('COMPLETED', 'COMPLETED');
        $body['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] = 'USD';
        $invalid[] = $body;
        $body['id'] = 'OTHER123';
        $invalid[] = $body;
        foreach ($invalid as $body) {
            $client = Mockery::mock(PayPalClient::class);
            $client->shouldReceive('getOrder')->once()->andReturn($this->details('APPROVED'));
            $client->shouldReceive('captureOrder')->once()->andReturn($body);
            $this->assertConfirmFailure($client);
        }
    }

    public function test_order_is_verified_before_capture(): void
    {
        foreach (['amount', 'currency', 'intent'] as $mismatch) {
            $body = $this->details('APPROVED');
            if ($mismatch === 'intent') {
                $body['intent'] = 'AUTHORIZE';
            } else {
                $body['purchase_units'][0]['amount'][$mismatch === 'amount' ? 'value' : 'currency_code'] = $mismatch === 'amount' ? '0.01' : 'USD';
            }
            $client = Mockery::mock(PayPalClient::class);
            $client->shouldReceive('getOrder')->once()->andReturn($body);
            $client->shouldNotReceive('captureOrder');
            $this->assertConfirmFailure($client);
        }
    }

    public function test_unapproved_or_incomplete_orders_do_not_trigger_capture_or_success(): void
    {
        foreach (['CREATED', 'PAYER_ACTION_REQUIRED', 'VOIDED', 'COMPLETED'] as $status) {
            $client = Mockery::mock(PayPalClient::class);
            $client->shouldReceive('getOrder')->once()->andReturn($this->details($status));
            $client->shouldNotReceive('captureOrder');
            $result = (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->confirm($this->payment(['external_id' => '5O190127TN364715T']), ['result' => 'success']);
            $this->assertSame('pending', $result->status);
            $this->assertNull($result->captureId);
        }
    }

    public function test_confirmation_sanitizes_lookup_and_capture_errors(): void
    {
        foreach (['getOrder', 'captureOrder'] as $method) {
            $client = Mockery::mock(PayPalClient::class);
            if ($method === 'captureOrder') {
                $client->shouldReceive('getOrder')->once()->andReturn($this->details('APPROVED'));
            } else {
                $client->shouldNotReceive('captureOrder');
            }
            $client->shouldReceive($method)->once()->andThrow(new RuntimeException('sandbox-secret access-token-sensitive'));
            $this->assertConfirmFailure($client);
        }
        $this->assertNoLogs();
    }

    public function test_confirmation_rejects_missing_external_id_before_client_calls(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldNotReceive('getOrder');
        $client->shouldNotReceive('captureOrder');
        $this->expectException(InvalidArgumentException::class);
        (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->confirm($this->payment());
    }

    public function test_amount_comparison_normalizes_decimal_strings_without_float(): void
    {
        $body = $this->details('COMPLETED', 'COMPLETED');
        $body['purchase_units'][0]['amount']['value'] = '12.3';
        $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'] = '12.30';
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldReceive('getOrder')->once()->andReturn($body);
        $client->shouldNotReceive('captureOrder');
        $result = (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->confirm($this->payment(['external_id' => '5O190127TN364715T', 'amount' => '12.30']));
        $this->assertSame('succeeded', $result->status);
    }

    private function details(string $status, ?string $captureStatus = null): array
    {
        $body = ['id' => '5O190127TN364715T', 'status' => $status, 'intent' => 'CAPTURE',
            'purchase_units' => [['amount' => ['value' => '12.34', 'currency_code' => 'EUR']]]];
        if ($captureStatus !== null) {
            $body['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE123', 'status' => $captureStatus,
                'amount' => ['value' => '12.34', 'currency_code' => 'EUR']]];
        }

        return $body;
    }

    private function assertConfirmFailure(PayPalClient $client): void
    {
        try {
            (new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL))->confirm($this->payment(['external_id' => '5O190127TN364715T']));
            $this->fail('Expected confirmation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PayPal Sandbox payment confirmation is unconfirmed; retry the same payment.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sandbox-secret', (string) $exception);
            $this->assertStringNotContainsString('access-token-sensitive', (string) $exception);
        }
    }

    private function payment(array $attributes = []): Payment
    {
        $payment = new Payment;
        $payment->setRawAttributes(array_replace(['id' => 42, 'order_id' => 123, 'provider' => 'paypal',
            'status' => 'pending', 'amount' => '12.34', 'currency' => 'EUR', 'external_id' => null], $attributes), true);
        $payment->exists = true;

        return $payment;
    }

    private function order(): array
    {
        return ['id' => '5O190127TN364715T',
            'approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'];
    }

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
