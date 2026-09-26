<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\DeliveryHour;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class DeliveryHourController extends TenantResourceController
{
    protected function model(): string
    {
        return DeliveryHour::class;
    }

    protected function rules(bool $update = false): array
    {
        $rule = $update ? 'sometimes' : 'required';

        return [
            'lavage_hour' => [$rule, 'integer', 'min:1'],
            'express_hour' => [$rule, 'integer', 'min:1'],
            'repassage_hour' => [$rule, 'integer', 'min:1'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
