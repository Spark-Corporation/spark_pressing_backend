<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\Agency;
use App\Models\CodeSuffix;
use Knuckles\Scribe\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Catalogue')]
class CodeSuffixController extends TenantResourceController
{
    protected function model(): string
    {
        return CodeSuffix::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:32'],
            'agency_id' => [$update ? 'sometimes' : 'required', 'exists:agencies,id'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    public function index(): JsonResponse
    {
        $items = CodeSuffix::query()->with('agency:id,name')->latest()->paginate(50);

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->assertAgency($data['agency_id'], (int) $request->user()->pressing_id);

        $item = CodeSuffix::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => $data['status'] ?? true,
        ]);

        return $this->created($item->load('agency:id,name'));
    }

    private function assertAgency(int $agencyId, int $pressingId): void
    {
        abort_unless(
            Agency::query()->where('id', $agencyId)->where('pressing_id', $pressingId)->exists(),
            403
        );
    }
}
