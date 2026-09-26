<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pressing_id' => $this->pressing_id,
            'name' => $this->name,
            'address' => $this->address,
            'contact' => $this->contact,
            'country_code' => $this->country_code,
            'code_prefix' => $this->code_prefix,
            'code_suffix' => $this->code_suffix,
            'status' => $this->status,
        ];
    }
}
