<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'service_id' => $this->service_id,
            'designation' => $this->designation,
            'pricing_type' => $this->pricing_type,
            'quantity' => $this->quantity,
            'weight_kg' => $this->weight_kg,
            'type_action' => $this->type_action,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'retrieve_quantity' => $this->retrieve_quantity,
            'state' => $this->state,
            'render_id' => $this->render_id,
        ];
    }
}
