<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seller' => ['id' => $this->seller->id, 'name' => $this->seller->name],
            'price' => $this->price,
            'quantity' => (int) $this->quantity,
        ];
    }
}
