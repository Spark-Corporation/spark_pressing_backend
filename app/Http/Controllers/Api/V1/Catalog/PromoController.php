<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\Promo;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class PromoController extends TenantResourceController
{
    protected function model(): string
    {
        return Promo::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'code' => [$update ? 'sometimes' : 'required', 'string', 'max:32'],
            'rate_percent' => [$update ? 'sometimes' : 'required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
