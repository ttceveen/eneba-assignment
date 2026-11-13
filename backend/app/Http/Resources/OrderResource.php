<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'items' => $this->items->map(fn ($item): array => [
                'id' => $item->id, 'offer_id' => $item->offer_id, 'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'game' => ['id' => $item->offer->game->id, 'name' => $item->offer->game->name, 'img' => $item->offer->game->img],
                'seller' => ['id' => $item->offer->seller->id, 'name' => $item->offer->seller->name],
            ])->all(),
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'reserved_until' => $this->reserved_until->toISOString(),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
