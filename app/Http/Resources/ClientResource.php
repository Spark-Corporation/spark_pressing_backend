<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pressing_id' => $this->pressing_id,
            'agency_id' => $this->agency_id,
            'code' => $this->code,
            'fullname' => $this->fullname,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'address' => $this->address,
            'city' => $this->city,
            'loyalty_points' => $this->loyalty_points,
            'wallet_balance' => (int) ($this->wallet_balance ?? 0),
            'sponsor_code' => $this->sponsor_code,
            'referred_by_id' => $this->referred_by_id,
            'last_deposit_at' => $this->last_deposit_at,
            'status' => $this->status,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone_secondary' => $this->fixe_number,
            'contact_preference' => $this->contact_preference,
            'consent_sms' => (bool) $this->consent_sms,
            'consent_email' => (bool) $this->consent_email,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'tier' => $this->resource->tier(),
            'agency' => $this->whenLoaded('agency', fn () => $this->agency ? [
                'id' => $this->agency->id,
                'name' => $this->agency->name,
            ] : null),
            'deposits_count' => $this->when(isset($this->deposits_count), (int) $this->deposits_count),
        ];
    }
}
