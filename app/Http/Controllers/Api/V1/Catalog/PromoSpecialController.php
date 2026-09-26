<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\PromoSpecial;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class PromoSpecialController extends TenantResourceController
{
    protected function model(): string
    {
        return PromoSpecial::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'code' => [$update ? 'sometimes' : 'required', 'string', 'max:32'],
            'rate_percent' => [$update ? 'sometimes' : 'required', 'integer', 'min:1', 'max:100'],
            'max_clients' => ['nullable', 'integer', 'min:1'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
