<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'classic_price' => $this->classic_price,
            'express_price' => $this->express_price,
            'repass_price' => $this->repass_price,
            'classic_price_kilo' => $this->classic_price_kilo,
            'express_price_kilo' => $this->express_price_kilo,
            'repass_price_kilo' => $this->repass_price_kilo,
            'status' => $this->status,
            'code' => $this->code,
            'icon' => $this->icon,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'billing_mode' => $this->billing_mode ?? 'piece',
            'allow_discount' => (bool) ($this->allow_discount ?? true),
            'round_to' => (int) ($this->round_to ?? 0),
            'price_editable' => (bool) $this->price_editable,
            'delays' => ['classic' => $this->classic_hours, 'express' => $this->express_hours, 'repass' => $this->repass_hours],
            'priority' => $this->priority ?? 'standard',
            'accepted_defects' => $this->accepted_defects ?? [],
            'compatible_renders' => $this->compatible_renders ?? [],
            'services' => $this->services ?? [],
            'price_updated_at' => $this->price_updated_at?->toIso8601String(),
        ];
    }
}
