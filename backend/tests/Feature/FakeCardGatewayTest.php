<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\FakeCardGateway;
use App\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FakeCardGatewayTest extends TestCase
{
    public function test_production_requires_a_strictly_enabled_demo_flag(): void
    {
        app()->instance('env', 'production');
        Http::preventStrayRequests();
        foreach ([false, null, 0, 1, 'true'] as $disabled) {
            config(['payment.demo_enabled' => $disabled]);
            try {
                app(PaymentGatewayRegistry::class)->get('fake_card');
                $this->fail('Fake Card accepted a demo flag other than boolean true.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame('Demo payments are disabled.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_production_explicitly_enabled_demo_supports_pending_success_and_failure(): void
    {
        app()->instance('env', 'production');
        config(['payment.demo_enabled' => true]);
        Http::preventStrayRequests();
        $gateway = app(PaymentGatewayRegistry::class)->forSimulation('fake_card');
        $this->assertInstanceOf(FakeCardGateway::class, $gateway);
        $payment = new Payment;
        $payment->forceFill(['id' => 42]);
        $result = $gateway->create($payment);
        $this->assertSame('pending', $result->status);
        $this->assertSame('fake_card_42', $result->externalId);
        $this->assertSame('succeeded', $gateway->confirm($payment, ['result' => 'success'])->status);
        $declined = $gateway->confirm($payment, ['result' => 'failure']);
        $this->assertSame('failed', $declined->status);
        $this->assertSame('demo_declined', $declined->errorCode);
        $this->assertTrue(app()->environment('production'));
        Http::assertNothingSent();
    }

    public function test_existing_gateway_rechecks_the_flag_for_creation_and_confirmation(): void
    {
        app()->instance('env', 'production');
        config(['payment.demo_enabled' => true]);
        $gateway = new FakeCardGateway;
        config(['payment.demo_enabled' => false]);
        foreach (['create', 'confirm'] as $operation) {
            try {
                $operation === 'create' ? $gateway->create(new Payment) : $gateway->confirm(new Payment, ['result' => 'success']);
                $this->fail('Disabled Fake Card operation was allowed.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }
}
