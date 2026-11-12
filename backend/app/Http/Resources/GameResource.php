<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'img' => $this->img,
            'min_price' => $this->whenHas('min_price'),
            'offers_count' => $this->whenHas('offers_count'),
            'offers' => OfferResource::collection($this->whenLoaded('availableOffers')),
        ];
    }
}
