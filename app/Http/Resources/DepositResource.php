<?php

namespace App\Http\Resources;

use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itemsCount = isset($this->items_count) ? (int) $this->items_count : null;

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'code' => $this->code,
            'qr_token' => $this->qr_token,
            'pressing_id' => $this->pressing_id,
            'agency_id' => $this->agency_id,
            'currency' => $this->currency,
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
            'etat_label' => Deposit::etatLabel($this->etat),
            'colonne' => Deposit::etatColumn($this->etat),
            'delivery_status' => $this->delivery_status,
            'delivery_round_id' => $this->delivery_round_id,
            'delivery_zone_id' => $this->delivery_zone_id,
            'livreur_id' => $this->livreur_id,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivery_confirmation_type' => $this->delivery_confirmation_type,
            'delivery_confirmed_at' => $this->delivery_confirmed_at?->toIso8601String(),
            'delivery_address' => $this->delivery_address,
            'receiver_name' => $this->receiver_name,
            'notes' => $this->notes,
            'items_count' => $this->when($itemsCount !== null, $itemsCount),
            'articles_label' => $this->when(
                $itemsCount !== null,
                $itemsCount === 1 ? '1 pièce' : "{$itemsCount} pièces"
            ),
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->fullname),
            'client_phone' => $this->whenLoaded('client', fn () => $this->client?->phone_number),
            'agency' => $this->whenLoaded('agency', fn () => $this->agency ? [
                'id' => $this->agency->id,
                'name' => $this->agency->name,
            ] : null),
            'client' => new ClientResource($this->whenLoaded('client')),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier?->id,
                'fullname' => $this->cashier?->fullname,
            ]),
            'laveur' => $this->whenLoaded('laveur', fn () => $this->laveur ? [
                'id' => $this->laveur->id,
                'fullname' => $this->laveur->fullname,
            ] : null),
            'classeur' => $this->whenLoaded('classeur', fn () => $this->classeur ? [
                'id' => $this->classeur->id,
                'fullname' => $this->classeur->fullname,
            ] : null),
            'units' => DepositUnitResource::collection($this->whenLoaded('units')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
