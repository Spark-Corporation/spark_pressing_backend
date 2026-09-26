<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Pressing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Knuckles\Scribe\Attributes\Group;

#[Group('Superadmin')]
class LicenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = License::query()
            ->with('pressing:id,name')
            ->when($request->pressing_id, fn ($q, $id) => $q->where('pressing_id', $id))
            ->latest()
            ->paginate(50);

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pressing_id' => ['required', 'exists:pressings,id'],
            'plan' => ['nullable', 'string', 'max:32'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'months' => ['nullable', 'integer', 'min:1'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $license = License::query()->create([
            'pressing_id' => $data['pressing_id'],
            'plan' => $data['plan'] ?? 'standard',
            'seats' => $data['seats'] ?? 5,
            'code' => strtoupper(Str::random(12)),
            'status' => true,
        ]);

        if ($request->boolean('activate')) {
            $this->activateLicense($license, (int) ($data['months'] ?? 12));
        }

        return $this->created($license->fresh('pressing:id,name'));
    }

    public function update(Request $request, License $license): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', 'max:32'],
            'seats' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $license->update($data);

        return $this->ok($license->fresh('pressing:id,name'));
    }

    public function activate(Request $request, License $license): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->activateLicense($license, (int) ($data['months'] ?? 12));

        return $this->ok($license->fresh());
    }

    public function destroy(License $license): JsonResponse
    {
        $license->delete();

        return $this->ok(['deleted' => true]);
    }

    public function current(Request $request): JsonResponse
    {
        $pressingId = $request->user()?->pressing_id;
        abort_unless($pressingId, 404);

        $license = License::query()
            ->where('pressing_id', $pressingId)
            ->latest()
            ->first();

        return $this->ok($license ? [
            'id' => $license->id,
            'plan' => $license->plan,
            'seats' => $license->seats,
            'is_activated' => $license->is_activated,
            'is_valid' => $license->isValid(),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'pressing' => Pressing::query()->find($pressingId)?->name,
        ] : null);
    }

    private function activateLicense(License $license, int $months): void
    {
        $license->update([
            'is_activated' => true,
            'activated_at' => now(),
            'expires_at' => now()->addMonths($months),
            'status' => true,
        ]);
    }
}
