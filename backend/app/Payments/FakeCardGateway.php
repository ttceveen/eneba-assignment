<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Validator;

class FakeCardGateway implements PaymentGatewayInterface
{
    public function __construct()
    {
        // Registry resolution rejects disabled demo providers before creating DB attempts.
        $this->assertDemo();
    }

    private function assertDemo(): void
    {
        abort_unless(config('payment.demo_enabled') === true && app()->environment(['local', 'testing', 'demo']), 403, 'Demo payments are disabled.');
    }

    public function create(Payment $payment): PaymentResult
    {
        $this->assertDemo();

        return new PaymentResult('pending', 'fake_card_'.$payment->id);
    }

    public function confirm(Payment $payment, array $context = []): PaymentResult
    {
        $this->assertDemo();
        $data = Validator::make($context, ['result' => ['required', 'in:success,failure']])->validate();

        return $data['result'] === 'success'
            ? new PaymentResult('succeeded', 'fake_card_'.$payment->id)
            : new PaymentResult('failed', 'fake_card_'.$payment->id, errorCode: 'demo_declined', errorMessage: 'The demo payment was declined.');
    }

    public function supportsSimulation(): bool
    {
        return true;
    }
}
