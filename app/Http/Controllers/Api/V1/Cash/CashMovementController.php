<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashMovementResource;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Pressing;
use App\Support\Cash\CashPresenter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

#[Group('Caisse')]
class CashMovementController extends Controller
{
    public function __construct(private readonly CashPresenter $presenter) {}

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
            'payment_method' => ['nullable', 'in:cash,mobile_money,card,other'],
            'beneficiary' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $uuid = $data['client_uuid'] ?? (string) Str::uuid();
        $existing = CashMovement::withTrashed()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return $this->ok((new CashMovementResource($existing))->resolve());
        }

        $user = $request->user();
        $agencyId = app(TenantContext::class)->agencyId ?? $user->agency_id;
        $method = $data['payment_method'] ?? 'cash';
        $threshold = (int) (Pressing::query()->whereKey($user->pressing_id)->value('cash_double_control_threshold') ?? 250000);
        $requiresApproval = $data['type'] === 'out' && (int) $data['amount'] > $threshold;

        // « Validation avant enregistrement » : points bloquants.
        $errors = [];
        if ($requiresApproval && empty($data['category_id'])) {
            $errors['category_id'] = 'Sélectionnez une catégorie obligatoire.';
        }
        if ($requiresApproval && ! $request->hasFile('proof')) {
            $errors['proof'] = 'Ajoutez une pièce justificative valide.';
        }
        if ($data['type'] === 'out' && $method === 'cash'
            && $this->presenter->availableCash((int) $agencyId, CarbonImmutable::now()) < (int) $data['amount']) {
            $errors['amount'] = 'Le solde espèces disponible est insuffisant.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $movement = CashMovement::query()->create([
            'client_uuid' => $uuid,
            'pressing_id' => $user->pressing_id,
            'agency_id' => $agencyId,
            'user_id' => $user->id,
            'category_id' => $data['category_id'] ?? null,
            'label' => $data['label'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'action_date' => $data['action_date'] ?? now(),
            'validated' => $requiresApproval ? false : ($data['validated'] ?? false),
            'payment_method' => $method,
            'beneficiary' => $data['beneficiary'] ?? null,
            'reference' => $data['reference'] ?? null,
            'comment' => $data['comment'] ?? null,
            'requires_approval' => $requiresApproval,
            'cash_session_id' => $this->presenter->session((int) $agencyId, CarbonImmutable::now())?->id,
        ]);

        if ($request->hasFile('proof')) {
            $movement->update(['proof_path' => $request->file('proof')->store("pressings/{$user->pressing_id}/cash")]);
        }

        AuditLog::record('cash.movement', $movement, [
            'type' => $movement->type,
            'amount' => $movement->amount,
            'requires_approval' => $requiresApproval,
        ]);

        return $this->created($this->detail($movement->fresh()));
    }

    /** Détail du mouvement : traçabilité horodatée, double contrôle, caisse concernée. */
    public function show(CashMovement $cashMovement): JsonResponse
    {
        return $this->ok($this->detail($cashMovement));
    }

    private function detail(CashMovement $movement): array
    {
        $movement->loadMissing(['category:id,name', 'operator:id,fullname', 'approver:id,fullname']);
        $session = $movement->cash_session_id ? CashSession::query()->find($movement->cash_session_id) : null;
        $logs = AuditLog::query()
            ->where('subject_type', CashMovement::class)
            ->where('subject_id', $movement->id)
            ->orderBy('id')
            ->get();

        $trace = $logs->map(fn (AuditLog $log) => [
            'at' => $log->created_at?->toIso8601String(),
            'title' => match ($log->action) {
                'cash.movement' => 'Brouillon créé',
                'cash.validated' => $movement->requires_approval ? 'Seconde validation' : 'Mouvement validé',
                default => $log->action,
            },
            'detail' => $log->action === 'cash.movement' ? $movement->operator?->fullname : $movement->approver?->fullname,
            'done' => true,
        ])->all();
        if ($movement->requires_approval) {
            array_splice($trace, 1, 0, [[
                'at' => $movement->created_at?->toIso8601String(),
                'title' => 'Montant sensible détecté',
                'detail' => 'Double contrôle requis au-delà du seuil du pressing',
                'done' => true,
            ]]);
            if (! $movement->validated) {
                $trace[] = ['at' => null, 'title' => 'Seconde validation', 'detail' => 'En attente d’un manager habilité', 'done' => false];
            }
        }

        return (new CashMovementResource($movement))->resolve() + [
            'double_control' => $movement->requires_approval ? [
                'required' => true,
                'initiator' => ['fullname' => $movement->operator?->fullname, 'state' => 'Signé'],
                'approver' => $movement->approver
                    ? ['fullname' => $movement->approver->fullname, 'state' => 'Signé', 'at' => $movement->approved_at?->toIso8601String()]
                    : ['fullname' => null, 'state' => 'À notifier'],
            ] : ['required' => false],
            'traceability' => $trace,
            'cash_session' => $session ? [
                'id' => $session->id,
                'code' => $session->code,
                'status' => $session->status,
                'available_cash' => $this->presenter->availableCash((int) $movement->agency_id, CarbonImmutable::parse($session->business_date)),
            ] : null,
        ];
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

    /** Validation (seconde validation par un manager habilité au-delà du seuil). */
    public function validateMovement(Request $request, CashMovement $cashMovement): JsonResponse
    {
        $user = $request->user();
        if ($cashMovement->requires_approval) {
            abort_unless($user->can('cash.approve'), 403, 'Seconde validation réservée à un manager habilité.');
            if ((int) $cashMovement->user_id === (int) $user->id) {
                throw ValidationException::withMessages(['validated' => 'La seconde validation doit être faite par une autre personne.']);
            }
            $cashMovement->update(['approved_by' => $user->id, 'approved_at' => now()]);
        }

        $cashMovement->update(['validated' => true]);
        AuditLog::record('cash.validated', $cashMovement);

        return $this->ok($this->detail($cashMovement->fresh()));
    }

    public function destroy(CashMovement $cashMovement): JsonResponse
    {
        abort_if($cashMovement->validated, 422, 'Un mouvement validé ne peut plus être supprimé.');

        $cashMovement->delete();

        return $this->ok(['deleted' => true]);
    }
}
