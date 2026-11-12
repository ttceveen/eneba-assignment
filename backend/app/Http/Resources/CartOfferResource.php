<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quantity = min((int) $this->quantity, (int) $this->available_keys_count);

        return [
            'id' => $this->id,
            'game' => ['id' => $this->game->id, 'name' => $this->game->name, 'img' => $this->game->img],
            'seller' => ['id' => $this->seller->id, 'name' => $this->seller->name],
            'price' => $this->price,
            // The marketplace currently prices every offer in EUR.
            'currency' => 'EUR',
            'quantity' => $quantity,
            'available' => $quantity > 0 && $this->is_active && $this->game->is_active && $this->seller->is_active,
        ];
    }
}
