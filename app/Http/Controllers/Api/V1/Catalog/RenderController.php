<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\Render;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class RenderController extends TenantResourceController
{
    protected function model(): string
    {
        return Render::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:191'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
