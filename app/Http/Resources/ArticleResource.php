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
        ];
    }
}
