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
use LogicException;
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

    public function test_confirmation_and_simulation_are_not_available(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $client->shouldNotReceive('createOrder');
        $client->shouldNotReceive('captureOrder');
        $client->shouldNotReceive('getOrder');
        $gateway = new PayPalGateway($client, self::RETURN_URL, self::CANCEL_URL);
        $this->assertFalse($gateway->supportsSimulation());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('PayPal Sandbox payment confirmation is not implemented.');
        $gateway->confirm($this->payment(), ['result' => 'success']);
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
