<?php

namespace App\Services;

use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(private InventoryService $inventory) {}

    public function create(User $user, array $items, string $idempotencyKey): Order
    {
        abort_unless($user->status === 'active', 403, 'This account is blocked.');
        $data = Validator::make(['items' => $items, 'key' => $idempotencyKey], [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['required', 'array:offer_id,quantity'],
            'items.*.offer_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ])->validate();
        $normalized = [];
        foreach ($data['items'] as $item) {
            $id = (int) $item['offer_id'];
            if (isset($normalized[$id])) {
                throw ValidationException::withMessages(['items' => 'Duplicate offer IDs are not allowed.']);
            }
            $normalized[$id] = ['offer_id' => $id, 'quantity' => (int) $item['quantity']];
        }
        ksort($normalized, SORT_NUMERIC);
        $fingerprint = hash('sha256', json_encode(array_values($normalized), JSON_THROW_ON_ERROR));
        // Finish old orders before locking ANY offers for the new order.
        $this->expireForOffers(array_keys($normalized));

        return DB::transaction(function () use ($user, $normalized, $idempotencyKey, $fingerprint): Order {
            // PostgreSQL transaction-scoped lock serializes even a previously unseen key.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$user->id.':'.$idempotencyKey]);
            $existing = Order::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new CheckoutConflictException('The idempotency key was already used for a different cart.');
                }

                return $this->expireLocked($existing)->load('items');
            }
            $order = Order::create(['user_id' => $user->id, 'status' => 'pending_payment',
                'total_amount' => '0.00', 'currency' => 'EUR', 'reserved_until' => now()->addMinutes(15)]);
            $order->forceFill(['idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint])->save();
            $offers = $this->inventory->lockOffers(array_keys($normalized));
            if ($offers->count() !== count($normalized)) {
                throw ValidationException::withMessages(['items' => 'One or more offers do not exist.']);
            }
            $total = 0;
            foreach ($normalized as $item) {
                $offer = $offers->get($item['offer_id']);
                if (! $offer->is_active || ! $offer->game->is_active || ! $offer->seller->is_active) {
                    throw new CheckoutConflictException('An offer is no longer available.');
                }
                $cents = (int) str_replace('.', '', $offer->price);
                if ($cents > 0 && $item['quantity'] > intdiv(999999999999 - $total, $cents)) {
                    throw ValidationException::withMessages(['items' => 'The order total exceeds the supported amount.']);
                }
                $total += $cents * $item['quantity'];
                $order->items()->create(['offer_id' => $offer->id, 'quantity' => $item['quantity'], 'unit_price' => $offer->price]);
            }
            $order->update(['total_amount' => intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT),
                'reserved_until' => now()->addMinutes(15)]);
            $this->inventory->reserve($order);

            return $order->load('items');
        }, 3);
    }

    public function get(User $user, int $id): Order
    {
        return DB::transaction(function () use ($user, $id): Order {
            $order = Order::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();

            return $this->expireLocked($order)->load('items');
        }, 3);
    }

    public function cancel(User $user, int $id): Order
    {
        return DB::transaction(function () use ($user, $id): Order {
            $order = Order::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $order = $this->expireLocked($order);
            if ($order->status === 'paid') {
                throw new CheckoutConflictException('A paid order cannot be cancelled.');
            }
            if ($order->status === 'pending_payment') {
                $this->inventory->release($order);
                $order->update(['status' => 'cancelled']);
            }

            return $order->load('items');
        }, 3);
    }

    private function expireLocked(Order $order): Order
    {
        if ($order->status === 'pending_payment' && $order->reserved_until->lte(now())) {
            $this->inventory->release($order);
            $order->update(['status' => 'expired']);
        }

        return $order;
    }

    private function expireForOffers(array $ids): void
    {
        $expired = Order::query()->where('status', 'pending_payment')->where('reserved_until', '<=', now())
            ->whereHas('items', fn ($query) => $query->whereIn('offer_id', $ids))
            ->orderBy('reserved_until')->orderBy('id')->limit(50)->pluck('id');
        foreach ($expired as $id) {
            DB::transaction(function () use ($id): void {
                $order = Order::query()->whereKey($id)->lockForUpdate()->first();
                if ($order) {
                    $this->expireLocked($order);
                }
            }, 3);
        }
    }
}
