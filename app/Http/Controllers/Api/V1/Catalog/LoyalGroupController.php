<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyalGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class LoyalGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $items = LoyalGroup::query()->withCount('clients')->latest()->paginate(50);

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'rate_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $group = LoyalGroup::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => true,
        ]);

        return $this->created($group);
    }

    public function update(Request $request, LoyalGroup $loyalGroup): JsonResponse
    {
        $loyalGroup->update($request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'rate_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', 'boolean'],
        ]));

        return $this->ok($loyalGroup);
    }

    public function attach(Request $request, LoyalGroup $loyalGroup): JsonResponse
    {
        $data = $request->validate(['client_id' => ['required', 'exists:clients,id']]);
        $client = Client::query()->findOrFail($data['client_id']);
        $loyalGroup->clients()->syncWithoutDetaching([
            $client->id => ['pressing_id' => $request->user()->pressing_id],
        ]);

        return $this->ok(['attached' => true]);
    }

    public function detach(Request $request, LoyalGroup $loyalGroup): JsonResponse
    {
        $data = $request->validate(['client_id' => ['required', 'exists:clients,id']]);
        $loyalGroup->clients()->detach($data['client_id']);

        return $this->ok(['detached' => true]);
    }

    public function clients(LoyalGroup $loyalGroup): JsonResponse
    {
        $items = $loyalGroup->clients()->paginate(50);

        return $this->page($items, $items->items());
    }

    public function destroy(LoyalGroup $loyalGroup): JsonResponse
    {
        $loyalGroup->delete();

        return $this->ok(['deleted' => true]);
    }
}
