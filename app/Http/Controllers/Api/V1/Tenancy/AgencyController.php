<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Superadmin')]
class AgencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencies = Agency::query()
            ->when($request->pressing_id, fn ($q, $id) => $q->where('pressing_id', $id))
            ->latest()
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($agencies, AgencyResource::collection($agencies)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pressing_id' => ['required', 'exists:pressings,id'],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'code_prefix' => ['nullable', 'string', 'max:8'],
            'code_suffix' => ['nullable', 'string', 'max:8'],
        ]);

        $agency = Agency::query()->create($data + ['status' => true]);

        return $this->created((new AgencyResource($agency))->resolve());
    }

    public function show(Agency $agency): JsonResponse
    {
        return $this->ok((new AgencyResource($agency))->resolve());
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'code_prefix' => ['nullable', 'string', 'max:8'],
            'code_suffix' => ['nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'boolean'],
        ]);

        $agency->update($data);

        return $this->ok((new AgencyResource($agency))->resolve());
    }

    public function destroy(Agency $agency): JsonResponse
    {
        $agency->delete();

        return $this->ok(['deleted' => true]);
    }
}
