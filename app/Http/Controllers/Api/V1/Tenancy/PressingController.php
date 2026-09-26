<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Resources\PressingResource;
use App\Models\Pressing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Superadmin')]
class PressingController extends Controller
{
    public function index(): JsonResponse
    {
        $pressings = Pressing::query()->with('agencies')->latest()->paginate($this->perPage());

        return $this->page($pressings, PressingResource::collection($pressings)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'details' => ['nullable', 'string'],
            'pricing_mode' => ['nullable', 'in:piece,kilo,mixed'],
            'workflow_laveur_enabled' => ['sometimes', 'boolean'],
            'workflow_classeur_enabled' => ['sometimes', 'boolean'],
            'block_retrieve_if_unpaid' => ['sometimes', 'boolean'],
        ]);

        $pressing = Pressing::query()->create($data + ['status' => true]);

        return $this->created((new PressingResource($pressing))->resolve());
    }

    public function show(Pressing $pressing): JsonResponse
    {
        return $this->ok((new PressingResource($pressing->load('agencies')))->resolve());
    }

    public function update(Request $request, Pressing $pressing): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'details' => ['nullable', 'string'],
            'status' => ['sometimes', 'boolean'],
            'pricing_mode' => ['sometimes', 'in:piece,kilo,mixed'],
            'workflow_laveur_enabled' => ['sometimes', 'boolean'],
            'workflow_classeur_enabled' => ['sometimes', 'boolean'],
            'block_retrieve_if_unpaid' => ['sometimes', 'boolean'],
            'loyalty_points_rate' => ['sometimes', 'integer', 'min:0'],
            'hours_classic' => ['sometimes', 'integer', 'min:1'],
            'hours_express' => ['sometimes', 'integer', 'min:1'],
            'hours_repass' => ['sometimes', 'integer', 'min:1'],
        ]);

        $pressing->update($data);

        return $this->ok((new PressingResource($pressing->fresh('agencies')))->resolve());
    }

    public function destroy(Pressing $pressing): JsonResponse
    {
        $pressing->delete();

        return $this->ok(['deleted' => true]);
    }

    private function perPage(): int
    {
        return min(100, max(1, (int) request('per_page', 20)));
    }
}
