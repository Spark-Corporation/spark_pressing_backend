<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'amount' => $this->amount,
            'type' => $this->type,
            'payment_method' => $this->payment_method,
            'transaction_date' => $this->transaction_date?->toIso8601String(),
        ];
    }
}
