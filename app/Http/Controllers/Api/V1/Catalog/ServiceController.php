<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\Service;

class ServiceController extends TenantResourceController
{
    protected function model(): string
    {
        return Service::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:32'],
            'legacy_type_action' => ['nullable', 'integer', 'in:0,1,2'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'default_hours' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
