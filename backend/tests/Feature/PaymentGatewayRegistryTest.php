<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\FakeCardGateway;
use App\Payments\PaymentGatewayRegistry;
use App\Payments\PayPalClient;
use App\Payments\PayPalGateway;
use Illuminate\Support\Facades\Http;
use LogicException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentGatewayRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.paypal.enabled' => true, 'payment.paypal.return_url' => 'http://localhost:5173/return',
            'payment.paypal.cancel_url' => 'http://localhost:5173/cancel', 'payment.demo_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_enabled_paypal_resolves_through_the_existing_registry_and_container(): void
    {
        $this->assertSame(PayPalGateway::class, config('payment.gateways.paypal'));
        $gateway = app(PaymentGatewayRegistry::class)->get('paypal');
        $this->assertInstanceOf(PayPalGateway::class, $gateway);
        $this->assertFalse($gateway->supportsSimulation());
        Http::assertNothingSent();
    }

    public function test_disabled_paypal_is_unavailable_even_when_callback_urls_are_missing(): void
    {
        config(['payment.paypal.enabled' => false, 'payment.paypal.return_url' => '', 'payment.paypal.cancel_url' => '']);
        try {
            app(PaymentGatewayRegistry::class)->get('paypal');
            $this->fail('Disabled PayPal was resolved.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('PayPal Sandbox is disabled.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_fake_card_remains_available_with_paypal_disabled_and_demo_explicitly_enabled(): void
    {
        config(['payment.paypal.enabled' => false]);
        $gateway = app(PaymentGatewayRegistry::class)->forSimulation('fake_card');
        $this->assertInstanceOf(FakeCardGateway::class, $gateway);
        $payment = new Payment;
        $payment->forceFill(['id' => 42]);
        $this->assertSame('pending', $gateway->create($payment)->status);
        $this->assertSame('succeeded', $gateway->confirm($payment, ['result' => 'success'])->status);
    }

    public function test_fake_card_still_requires_the_demo_flag(): void
    {
        config(['payment.demo_enabled' => false]);
        $this->expectException(HttpException::class);
        app(PaymentGatewayRegistry::class)->get('fake_card');
    }

    public function test_configured_urls_and_injected_client_reach_the_gateway_without_hardcoded_addresses(): void
    {
        $client = Mockery::mock(PayPalClient::class);
        $this->app->instance(PayPalClient::class, $client);
        $payment = new Payment;
        $payment->forceFill(['id' => 42, 'amount' => '12.34', 'currency' => 'EUR']);
        $payment->exists = true;
        foreach (['https://shop.example.test', 'http://localhost:5173'] as $base) {
            config(['payment.paypal.return_url' => $base.'/payment-return', 'payment.paypal.cancel_url' => $base.'/payment-cancel']);
            $client->shouldReceive('createOrder')->once()->with('12.34', 'paypal-create-42', $base.'/payment-return', $base.'/payment-cancel')
                ->andReturn(['id' => 'ORDER123', 'approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123']);
            $result = app(PaymentGatewayRegistry::class)->get('paypal')->create($payment);
            $this->assertSame('ORDER123', $result->externalId);
        }
        Http::assertNothingSent();
    }

    public function test_paypal_cannot_be_used_for_simulation(): void
    {
        try {
            app(PaymentGatewayRegistry::class)->forSimulation('paypal');
            $this->fail('PayPal simulation was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_invalid_callback_configuration_is_rejected_before_resolving_a_gateway(): void
    {
        foreach (['payment.paypal.return_url', 'payment.paypal.cancel_url'] as $key) {
            config(['payment.paypal.return_url' => 'https://shop.test/return', 'payment.paypal.cancel_url' => 'https://shop.test/cancel']);
            config([$key => '']);
            try {
                app(PaymentGatewayRegistry::class)->get('paypal');
                $this->fail('Missing callback configuration was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('Configure valid PayPal Sandbox return and cancel URLs.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }
}
