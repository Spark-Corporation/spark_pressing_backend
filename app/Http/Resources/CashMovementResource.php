<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'label' => $this->label,
            'type' => $this->type,
            'amount' => $this->amount,
            'action_date' => $this->action_date?->toIso8601String(),
            'validated' => $this->validated,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category'),
            'reference_code' => sprintf('MVT-%05d', $this->id),
            'payment_method' => $this->payment_method,
            'beneficiary' => $this->beneficiary,
            'reference' => $this->reference,
            'comment' => $this->comment,
            'has_proof' => (bool) $this->proof_path,
            'requires_approval' => (bool) $this->requires_approval,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'status' => $this->resource->workflowStatus(),
            'operator' => $this->whenLoaded('operator', fn () => $this->operator?->fullname),
        ];
    }
}
