<?php

namespace App\Http\Resources;

use App\Models\Deposit;
use App\Models\LaundryStatus;
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
            'is_express' => (bool) $this->is_express,
            'express_supplement' => (int) $this->express_supplement,
            'color' => $this->color,
            'defects' => collect($this->defects ?? [])->map(fn ($code) => [
                'code' => $code,
                'label' => self::defectLabels((int) $this->pressing_id)[$code] ?? Deposit::DEFECTS[$code] ?? $code,
            ])->values(),
            'internal_notes' => $this->internal_notes,
        ];
    }

    /** @var array<int, array<string,string>> */
    private static array $defectLabels = [];

    private static function defectLabels(int $pressingId): array
    {
        return self::$defectLabels[$pressingId] ??= LaundryStatus::catalog($pressingId ?: null, false);
    }
}
