<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashMovementResource;
use App\Models\AuditLog;
use App\Models\CashMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Knuckles\Scribe\Attributes\Group;

#[Group('Caisse')]
class CashMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = CashMovement::query()
            ->with(['category:id,name'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('validated'), fn ($q) => $q->where('validated', $request->boolean('validated')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('action_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('action_date', '<=', $request->date_to))
            ->latest('action_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        $totals = CashMovement::query()
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('action_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('action_date', '<=', $request->date_to))
            ->selectRaw("coalesce(sum(case when type = 'in' then amount else 0 end),0) as cash_in,
                coalesce(sum(case when type = 'out' then amount else 0 end),0) as cash_out")
            ->first();

        return $this->page($items, CashMovementResource::collection($items)->resolve(), [
            'cash_in' => (int) ($totals->cash_in ?? 0),
            'cash_out' => (int) ($totals->cash_out ?? 0),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'integer', 'min:1'],
            'category_id' => ['nullable', 'exists:cash_movement_categories,id'],
            'action_date' => ['nullable', 'date'],
            'validated' => ['sometimes', 'boolean'],
        ]);

        $uuid = $data['client_uuid'] ?? (string) Str::uuid();
        $existing = CashMovement::withTrashed()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return $this->ok((new CashMovementResource($existing))->resolve());
        }

        $user = $request->user();

        $movement = CashMovement::query()->create([
            'client_uuid' => $uuid,
            'pressing_id' => $user->pressing_id,
            'agency_id' => $user->agency_id,
            'user_id' => $user->id,
            'category_id' => $data['category_id'] ?? null,
            'label' => $data['label'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'action_date' => $data['action_date'] ?? now(),
            'validated' => $data['validated'] ?? false,
        ]);

        AuditLog::record('cash.movement', $movement, [
            'type' => $movement->type,
            'amount' => $movement->amount,
        ]);

        return $this->created((new CashMovementResource($movement))->resolve());
    }

    public function update(Request $request, CashMovement $cashMovement): JsonResponse
    {
        abort_if($cashMovement->validated, 422, 'Un mouvement validé ne peut plus être modifié.');

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:191'],
            'type' => ['sometimes', 'in:in,out'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['nullable', 'exists:cash_movement_categories,id'],
            'action_date' => ['nullable', 'date'],
        ]);

        $cashMovement->update($data);

        return $this->ok((new CashMovementResource($cashMovement))->resolve());
    }

    public function validateMovement(CashMovement $cashMovement): JsonResponse
    {
        $cashMovement->update(['validated' => true]);
        AuditLog::record('cash.validated', $cashMovement);

        return $this->ok((new CashMovementResource($cashMovement))->resolve());
    }

    public function destroy(CashMovement $cashMovement): JsonResponse
    {
        abort_if($cashMovement->validated, 422, 'Un mouvement validé ne peut plus être supprimé.');

        $cashMovement->delete();

        return $this->ok(['deleted' => true]);
    }
}
