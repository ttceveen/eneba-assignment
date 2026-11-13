<?php

namespace App\Services;

use App\Exceptions\CheckoutConflictException;
use App\Models\Offer;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryService
{
    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Inventory operations require an enclosing transaction.');
        }
    }

    /** Caller must lock the order before acquiring offers. */
    public function lockOffers(array $ids): Collection
    {
        $this->requireTransaction();

        return Offer::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
            ->with(['game:id,is_active', 'seller:id,is_active'])->get()->keyBy('id');
    }

    /** Reserve all existing items atomically; nested calls use a savepoint. */
    public function reserve(Order $order): void
    {
        DB::transaction(fn () => $this->reserveLocked($order));
    }

    private function reserveLocked(Order $order): void
    {
        $this->requireTransaction();
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'pending_payment' || $order->reserved_until->lte(now())) {
            throw new CheckoutConflictException('The order reservation is no longer valid.');
        }
        $items = $order->items()->orderBy('offer_id')->get();
        if ($items->isEmpty() || DB::table('digital_keys')->whereIn('order_item_id', $items->modelKeys())->exists()) {
            throw new CheckoutConflictException('The order cannot be reserved again.');
        }
        $offers = $this->lockOffers($items->pluck('offer_id')->all());
        foreach ($items as $item) {
            $offer = $offers->get($item->offer_id);
            if (! $offer || ! $offer->is_active || ! $offer->game->is_active || ! $offer->seller->is_active) {
                throw new CheckoutConflictException('An offer is no longer available.');
            }
            if ($item->quantity < 1 || $offer->quantity < $item->quantity) {
                throw new CheckoutConflictException('Insufficient stock.');
            }
            $ids = DB::table('digital_keys')->where('offer_id', $offer->id)->where('status', 'available')
                ->orderBy('id')->limit($item->quantity)->lock('FOR UPDATE SKIP LOCKED')->pluck('id');
            if ($ids->count() !== $item->quantity) {
                throw new CheckoutConflictException('Insufficient available keys.');
            }
            $changed = Offer::query()->whereKey($offer->id)->where('quantity', '>=', $item->quantity)
                ->decrement('quantity', $item->quantity, ['updated_at' => now()]);
            if ($changed !== 1) {
                throw new CheckoutConflictException('Insufficient stock.');
            }
            DB::table('digital_keys')->whereIn('id', $ids)->update([
                'status' => 'reserved', 'order_item_id' => $item->id,
                'reserved_until' => $order->reserved_until, 'updated_at' => now(),
            ]);
        }
        if ($order->reserved_until->lte(now())) {
            throw new CheckoutConflictException('The order reservation has expired.');
        }
    }

    /** Only reserved keys of a pending order are released; sold keys never change. */
    public function release(Order $order): int
    {
        return DB::transaction(fn (): int => $this->releaseLocked($order));
    }

    private function releaseLocked(Order $order): int
    {
        $this->requireTransaction();
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'pending_payment') {
            return 0;
        }
        $items = $order->items()->orderBy('offer_id')->get();
        $this->lockOffers($items->pluck('offer_id')->all());
        $keys = DB::table('digital_keys')->select(['id', 'offer_id'])->whereIn('order_item_id', $items->modelKeys())
            ->where('status', 'reserved')->orderBy('offer_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($keys->groupBy('offer_id') as $offerId => $group) {
            $count = $group->count();
            if (Offer::query()->whereKey($offerId)->where('quantity', '<=', 2147483647 - $count)
                ->increment('quantity', $count, ['updated_at' => now()]) !== 1) {
                throw new CheckoutConflictException('Stock counter cannot be restored.');
            }
        }
        if ($keys->isNotEmpty()) {
            DB::table('digital_keys')->whereIn('id', $keys->pluck('id'))->where('status', 'reserved')->update([
                'status' => 'available', 'order_item_id' => null, 'reserved_until' => null, 'updated_at' => now(),
            ]);
        }

        return $keys->count();
    }

    /** Future payment completion: call BEFORE marking paid, inside the same transaction. */
    public function confirmSale(Order $order): void
    {
        $this->requireTransaction();
        DB::transaction(fn () => $this->confirmSaleLocked($order));
    }

    private function confirmSaleLocked(Order $order): void
    {
        $this->requireTransaction();
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $items = $order->items()->orderBy('offer_id')->get();
        $this->lockOffers($items->pluck('offer_id')->all());
        $keys = DB::table('digital_keys')->select(['id', 'order_item_id', 'status', 'reserved_until'])
            ->whereIn('order_item_id', $items->modelKeys())->orderBy('offer_id')->orderBy('id')->lockForUpdate()->get();
        $time = now();
        if ($order->status !== 'pending_payment' || $order->reserved_until->lte($time) || $items->isEmpty()) {
            throw new CheckoutConflictException('The order reservation is no longer valid.');
        }
        $byItem = $keys->groupBy('order_item_id');
        foreach ($items as $item) {
            $assigned = $byItem->get($item->id, collect());
            if ($assigned->count() !== $item->quantity || $assigned->contains(fn ($key): bool => $key->status !== 'reserved' || $key->reserved_until === null || Carbon::parse($key->reserved_until)->lte($time))) {
                throw new CheckoutConflictException('The reserved keys do not match the order.');
            }
        }
        DB::table('digital_keys')->whereIn('id', $keys->pluck('id'))->update([
            'status' => 'sold', 'reserved_until' => null, 'updated_at' => $time,
        ]);
    }
}
