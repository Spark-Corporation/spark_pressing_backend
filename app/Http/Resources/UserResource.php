<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullname' => $this->displayName(),
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'pressing_id' => $this->pressing_id,
            'agency_id' => $this->agency_id,
            'status' => $this->status,
            'roles' => $this->whenLoaded('roles', fn () => $this->getRoleNames()->values()),
            'permissions' => $this->when(
                $request->is('api/v1/auth/me') || $request->boolean('with_permissions'),
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),
            'agency' => new AgencyResource($this->whenLoaded('agency')),
            'agencies' => AgencyResource::collection($this->whenLoaded('agencies')),
            'pressing' => new PressingResource($this->whenLoaded('pressing')),
            'can_view_all_agencies' => $this->when(
                method_exists($this->resource, 'canViewAllAgencies'),
                fn () => $this->canViewAllAgencies()
            ),
        ];
    }
}
