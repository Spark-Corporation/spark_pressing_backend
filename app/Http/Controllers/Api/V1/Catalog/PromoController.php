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

    /** « Créer une promotion » : pourcentage ou montant, quotas, agences éligibles, minimum, plafond, cumul fidélité. */
    protected function rules(bool $update = false): array
    {
        return [
            'code' => [$update ? 'sometimes' : 'required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:191'],
            'type' => ['sometimes', 'in:percent,amount'],
            'rate_percent' => [$update ? 'sometimes' : 'required_unless:type,amount', 'nullable', 'integer', 'min:1', 'max:100'],
            'amount' => ['required_if:type,amount', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'per_client_limit' => ['nullable', 'integer', 'min:1'],
            'agency_ids' => ['nullable', 'array'],
            'agency_ids.*' => ['integer', 'exists:agencies,id'],
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:1'],
            'stack_with_loyalty' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
