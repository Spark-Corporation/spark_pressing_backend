<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PressingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'details' => $this->details,
            'status' => $this->status,
            'pricing_mode' => $this->pricing_mode,
            'workflow_laveur_enabled' => $this->workflow_laveur_enabled,
            'workflow_classeur_enabled' => $this->workflow_classeur_enabled,
            'qr_labels_enabled' => $this->qr_labels_enabled,
            'block_retrieve_if_unpaid' => $this->block_retrieve_if_unpaid,
            'reporting_currency' => $this->reporting_currency,
            'collection_fee' => $this->collection_fee,
            'delivery_fee' => $this->delivery_fee,
            'loyalty_points_rate' => $this->loyalty_points_rate,
            'hours_classic' => $this->hours_classic,
            'hours_express' => $this->hours_express,
            'hours_repass' => $this->hours_repass,
            'agencies' => AgencyResource::collection($this->whenLoaded('agencies')),
        ];
    }
}
