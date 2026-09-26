<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'code' => $this->code,
            'pressing_id' => $this->pressing_id,
            'agency_id' => $this->agency_id,
            'client_id' => $this->client_id,
            'deposit_date' => $this->deposit_date?->toIso8601String(),
            'retrieve_date' => $this->retrieve_date?->toIso8601String(),
            'retrieved_at' => $this->retrieved_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'collection_fee' => $this->collection_fee,
            'delivery_fee' => $this->delivery_fee,
            'discount' => $this->discount,
            'discount_percent' => $this->discount_percent,
            'promo_code' => $this->promo_code,
            'points_earned' => $this->points_earned,
            'points_redeemed' => $this->points_redeemed,
            'total' => $this->total,
            'advanced' => $this->advanced,
            'left_to_pay' => $this->left_to_pay,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'etat' => $this->etat,
            'receiver_name' => $this->receiver_name,
            'notes' => $this->notes,
            'items_count' => $this->when(isset($this->items_count), (int) $this->items_count),
            'client' => new ClientResource($this->whenLoaded('client')),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier?->id,
                'fullname' => $this->cashier?->fullname,
            ]),
            'units' => DepositUnitResource::collection($this->whenLoaded('units')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
