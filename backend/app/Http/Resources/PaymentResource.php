<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'provider' => $this->provider,
            'idempotency_key' => $this->idempotency_key, 'status' => $this->status, 'amount' => $this->amount, 'currency' => $this->currency,
            'external_id' => $this->external_id, 'redirect_url' => $this->redirect_url,
            'error_code' => $this->error_code, 'error_message' => $this->error_message];
    }
}
