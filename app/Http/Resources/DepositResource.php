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
        $units = $this->relationLoaded('units') ? $this->units : null;
        $pieces = isset($this->pieces_count) ? (int) $this->pieces_count : $units?->where('pricing_type', 'piece')->sum('quantity');
        $weight = isset($this->weight_total) ? (float) $this->weight_total : $units?->where('pricing_type', 'kilo')->sum('weight_kg');
        $minAction = isset($this->min_type_action) ? (int) $this->min_type_action : $units?->min('type_action');
        $maxAction = isset($this->max_type_action) ? (int) $this->max_type_action : $units?->max('type_action');

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
            'workshop_status' => $this->resource->workshopStatus(),
            'payment_status' => $this->resource->paymentStatus(),
            'service' => Deposit::serviceBadge((bool) $this->is_express, $minAction, $maxAction),
            'is_express' => (bool) $this->is_express,
            'express_supplement' => (int) $this->express_supplement,
            'pieces_count' => $pieces,
            'weight_kg_total' => $weight === null ? null : round((float) $weight, 2),
            'quantity_label' => self::quantityLabel((int) $pieces, (float) $weight),
            'terminal' => $this->terminal,
            'local_ref' => $this->local_ref,
            'storage_location' => $this->storage_location,
            'discount_reason' => $this->discount_reason,
            'sync_status' => ['code' => 'synced', 'label' => 'À jour', 'synced_at' => $this->updated_at?->toIso8601String()],
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

    /** « 7 pièces », « 4,8 kg » ou « 6 pièces · 3,4 kg ». */
    public static function quantityLabel(int $pieces, float $weight): ?string
    {
        $parts = [];
        if ($pieces > 0) {
            $parts[] = $pieces === 1 ? '1 pièce' : "{$pieces} pièces";
        }
        if ($weight > 0) {
            $parts[] = rtrim(rtrim(number_format($weight, 2, ',', ' '), '0'), ',').' kg';
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
