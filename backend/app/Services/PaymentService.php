<?php

namespace App\Services;

use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentGatewayRegistry;
use App\Payments\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private CheckoutService $checkout,
        private InventoryService $inventory,
    ) {}

    public function create(User $user, int $orderId, string $provider, string $key): Payment
    {
        $this->requireNoTransaction();
        Validator::make(['provider' => $provider, 'key' => $key], [
            'provider' => ['required', 'string', 'max:50'],
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ])->validate();
        $gateway = $this->gateways->get($provider);
        // Commit lazy expiration independently, even when a new attempt is refused.
        $this->checkout->get($user, $orderId);
        $fingerprint = hash('sha256', $provider);
        [$payment, $created] = DB::transaction(function () use ($user, $orderId, $provider, $key, $fingerprint): array {
            $order = Order::query()->whereKey($orderId)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = $order->payments()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new CheckoutConflictException('This payment idempotency key was used with a different provider.');
                }

                return [$existing, false];
            }
            if ($order->status !== 'pending_payment' || $order->reserved_until->lte(now())) {
                $this->checkout->get($user, $orderId);

                return [null, false];
            }
            if ($order->payments()->where('status', 'pending')->exists()) {
                throw new CheckoutConflictException('An active payment attempt already exists.');
            }
            $payment = $order->payments()->create(['provider' => $provider, 'status' => 'pending', 'amount' => $order->total_amount, 'currency' => $order->currency]);
            $payment->forceFill(['idempotency_key' => $key, 'request_fingerprint' => $fingerprint])->save();

            return [$payment, true];
        }, 3);
        if ($payment === null) {
            throw new CheckoutConflictException('This order cannot be paid.');
        }
        if (! $created && ($payment->status !== 'pending' || $payment->external_id !== null || $payment->redirect_url !== null)) {
            return $payment;
        }
        // Provider calls MUST stay outside transactions. Adapters use payment ID for idempotency.
        try {
            $result = $gateway->create($payment);
        } catch (\Throwable) {
            $current = $this->applyResult($payment->id, new PaymentResult('pending', errorCode: 'gateway_unavailable', errorMessage: 'Payment initiation is unconfirmed; retry with the same idempotency key.'));
            if ($current->status !== 'pending') {
                return $current;
            }
            throw new HttpException(502, 'The payment provider could not initialize this attempt.');
        }

        return $this->applyResult($payment->id, $result);
    }

    public function simulate(User $user, int $paymentId, string $result): Payment
    {
        $this->requireNoTransaction();
        Validator::make(['result' => $result], ['result' => ['required', 'in:success,failure']])->validate();
        $payment = Payment::query()->whereKey($paymentId)->whereHas('order', fn ($query) => $query->where('user_id', $user->id))->firstOrFail();
        $gateway = $this->gateways->forSimulation($payment->provider);
        $verified = $gateway->confirm($payment, ['result' => $result]);

        return $this->applyResult($payment->id, $verified);
    }

    /** Internal trusted boundary: call only with results obtained from gateway adapters. */
    public function applyResult(int $paymentId, PaymentResult $result): Payment
    {
        $snapshot = Payment::findOrFail($paymentId);
        $payment = DB::transaction(function () use ($snapshot, $result): Payment {
            $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                return $payment;
            }
            if ($order->status !== 'pending_payment' || $order->reserved_until->lte(now())) {
                return $this->rejectClosedOrder($order, $payment, $result);
            }
            if ($payment->amount !== $order->total_amount || $payment->currency !== $order->currency) {
                throw new CheckoutConflictException('The payment does not match the order amount or currency.');
            }
            if ($payment->external_id !== null && $result->externalId !== null && $payment->external_id !== $result->externalId) {
                throw new CheckoutConflictException('The gateway payment identity changed.');
            }
            if ($result->status === 'succeeded') {
                try {
                    // Savepoint lets expiration roll back ALL sale/statistics writes first.
                    DB::transaction(function () use ($order): void {
                        $this->inventory->confirmSale($order);
                        $totals = DB::table('order_items')->join('offers', 'offers.id', '=', 'order_items.offer_id')
                            ->where('order_items.order_id', $order->id)->selectRaw('offers.game_id, SUM(order_items.quantity) AS units')
                            ->groupBy('offers.game_id')->orderBy('offers.game_id')->get();
                        foreach ($totals as $total) {
                            $updated = DB::table('game_popularities')->where('game_id', $total->game_id)->increment('purchase_count', (int) $total->units);
                            if ($updated !== 1) {
                                throw new CheckoutConflictException('Purchase statistics are missing for this game.');
                            }
                        }
                        if ($order->reserved_until->lte(now())) {
                            throw new CheckoutConflictException('The reservation expired during completion.');
                        }
                        $order->update(['status' => 'paid']);
                    });
                } catch (CheckoutConflictException $exception) {
                    if ($order->reserved_until->lte(now())) {
                        return $this->rejectClosedOrder($order, $payment, $result);
                    }
                    throw $exception;
                }
            }
            $payment->forceFill([
                'status' => $result->status, 'external_id' => $result->externalId ?? $payment->external_id,
                'redirect_url' => $result->redirectUrl, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage,
            ])->save();

            return $payment;
        }, 3);
        if (in_array($payment->error_code, ['reservation_expired', 'order_closed', 'late_success'], true)) {
            // Throw after COMMIT: expiration and inventory release must not roll back.
            throw new CheckoutConflictException('The order is closed or its reservation has expired.');
        }

        return $payment;
    }

    private function rejectClosedOrder(Order $order, Payment $payment, PaymentResult $result): Payment
    {
        // Same order lock is already held. Checkout owns all expiration/release logic.
        $order = $this->checkout->get($order->user, $order->id);
        $payment->forceFill(['status' => 'cancelled', 'external_id' => $payment->external_id ?? $result->externalId,
            'error_code' => $result->status === 'succeeded' ? 'late_success' : ($order->status === 'expired' ? 'reservation_expired' : 'order_closed'),
            'error_message' => 'The order can no longer be paid.', 'redirect_url' => null])->save();

        return $payment;
    }

    private function requireNoTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Gateway operations must start outside a database transaction.');
        }
    }

    public function keys(User $user, int $orderId): array
    {
        $order = Order::query()->whereKey($orderId)->where('user_id', $user->id)->firstOrFail();
        if ($order->status !== 'paid') {
            throw new CheckoutConflictException('Keys are available only for a paid order.');
        }
        $items = $order->items()->with(['digitalKeys' => fn ($query) => $query->select(['id', 'order_item_id', 'key_value'])->where('status', 'sold')->orderBy('id')])->orderBy('id')->get();

        return ['order_id' => $order->id, 'items' => $items->map(fn ($item): array => [
            'order_item_id' => $item->id, 'offer_id' => $item->offer_id,
            'keys' => $item->digitalKeys->map(fn ($key): array => ['id' => $key->id, 'key_value' => $key->key_value])->all(),
        ])->all()];
    }
}
