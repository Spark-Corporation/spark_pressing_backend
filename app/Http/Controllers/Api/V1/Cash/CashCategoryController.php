<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Controller;
use App\Models\CashMovementCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Caisse')]
class CashCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $items = CashMovementCategory::query()
            ->where(function ($q) {
                $q->whereNull('pressing_id')->orWhere('pressing_id', auth()->user()->pressing_id);
            })
            ->get();

        return $this->ok($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'direction' => ['required', 'in:in,out'],
        ]);

        $item = CashMovementCategory::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => true,
        ]);

        return $this->created($item);
    }
}
