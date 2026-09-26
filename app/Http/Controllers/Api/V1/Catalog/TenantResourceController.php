<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class TenantResourceController extends Controller
{
    abstract protected function model(): string;

    abstract protected function rules(bool $update = false): array;

    public function index(): JsonResponse
    {
        $items = $this->model()::query()->latest()->paginate(50);

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['pressing_id'] = $request->user()->pressing_id;
        $data['status'] = $data['status'] ?? true;

        $item = $this->model()::query()->create($data);

        return $this->created($item);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Model $item */
        $item = $this->model()::query()->findOrFail($id);
        $item->update($request->validate($this->rules(true)));

        return $this->ok($item);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->model()::query()->findOrFail($id)->delete();

        return $this->ok(['deleted' => true]);
    }
}
