<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Actions\Delivery\AssignToRound;
use App\Actions\Delivery\MarkDelivered;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\DeliveryRound;
use App\Models\DeliveryZone;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Group;

#[Group('Livraison', 'Module Livreur v3.0 — tournées, marquage livré, encaissement mobile.')]
class DeliveryController extends Controller
{
    public function zonesIndex(Request $request): JsonResponse
    {
        $items = DeliveryZone::query()
            ->when($request->agency_id, fn ($q, $id) => $q->where('agency_id', $id))
            ->where('status', true)
            ->latest()
            ->paginate(50);

        return $this->page($items, $items->items());
    }

    public function zonesStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'status' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['agency_id'])) {
            abort_unless($request->user()->canAccessAgency((int) $data['agency_id']), 403);
        }

        $zone = DeliveryZone::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => $data['status'] ?? true,
        ]);

        return $this->created($zone);
    }

    public function zonesUpdate(Request $request, DeliveryZone $zone): JsonResponse
    {
        abort_unless((int) $zone->pressing_id === (int) $request->user()->pressing_id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'status' => ['sometimes', 'boolean'],
        ]);

        $zone->update($data);

        return $this->ok($zone);
    }

    public function roundsIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = DeliveryRound::query()
            ->with(['livreur:id,fullname', 'zone:id,name,code', 'deposits:id,code,delivery_status,delivery_round_id'])
            ->when($request->round_date, fn ($q, $d) => $q->whereDate('round_date', $d))
            ->when($request->zone_id, fn ($q, $id) => $q->where('delivery_zone_id', $id))
            ->when(
                $user->hasRole('livreur') && ! $user->canViewAllAgencies(),
                fn ($q) => $q->where('livreur_id', $user->id)
            )
            ->latest('round_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($items, $items->items());
    }

    public function roundsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'livreur_id' => ['required', 'exists:users,id'],
            'delivery_zone_id' => ['nullable', 'exists:delivery_zones,id'],
            'round_date' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        abort_unless($request->user()->canAccessAgency((int) $data['agency_id']), 403);

        $livreur = User::query()->findOrFail($data['livreur_id']);
        abort_unless((int) $livreur->pressing_id === (int) $request->user()->pressing_id, 403);
        abort_unless($livreur->hasRole('livreur') || $livreur->canViewAllAgencies(), 422, 'Utilisateur non livreur.');

        $round = DeliveryRound::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => DeliveryRound::STATUS_OPEN,
        ]);

        return $this->created($round->load(['livreur:id,fullname', 'zone:id,name']));
    }

    public function assign(Request $request, DeliveryRound $round, AssignToRound $action): JsonResponse
    {
        abort_unless((int) $round->pressing_id === (int) $request->user()->pressing_id, 403);

        $data = $request->validate([
            'deposit_ids' => ['required', 'array', 'min:1'],
            'deposit_ids.*' => ['integer', 'exists:deposits,id'],
        ]);

        $round = $action->handle($request->user(), $round, $data['deposit_ids']);

        return $this->ok($round);
    }

    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code', 'agency:id,name,currency'])
            ->where('status', true)
            ->whereIn('etat', [Deposit::ETAT_CLASSED, Deposit::ETAT_TREATED])
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                    ->orWhereIn('delivery_status', [
                        Deposit::DELIVERY_PENDING,
                        Deposit::DELIVERY_ASSIGNED,
                        Deposit::DELIVERY_OUT,
                    ]);
            })
            ->when($request->zone_id, fn ($q, $id) => $q->where('delivery_zone_id', $id))
            ->when($request->round_id, fn ($q, $id) => $q->where('delivery_round_id', $id))
            ->when(
                $user->hasRole('livreur') && ! $user->canViewAllAgencies(),
                fn ($q) => $q->where(function ($w) use ($user) {
                    $w->where('livreur_id', $user->id)->orWhereNull('livreur_id');
                })
            )
            ->when($request->boolean('mine'), fn ($q) => $q->where('livreur_id', $user->id))
            ->latest('retrieve_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code'])
            ->where('delivery_status', Deposit::DELIVERY_DELIVERED)
            ->when(
                $user->hasRole('livreur') && ! $user->canViewAllAgencies(),
                fn ($q) => $q->where('livreur_id', $user->id)
            )
            ->latest('delivered_at')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    public function markDelivered(Request $request, Deposit $deposit, MarkDelivered $action): JsonResponse
    {
        $payload = $request->validate([
            'confirmation_type' => ['required', Rule::in(['signature', 'code'])],
            'confirmation_value' => ['required', 'string'],
            'receiver_name' => ['nullable', 'string', 'max:191'],
            'collect_amount' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'require_settled' => ['sometimes', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function scheduleDeposit(Request $request, Deposit $deposit): JsonResponse
    {
        $data = $request->validate([
            'delivery_zone_id' => ['nullable', 'exists:delivery_zones,id'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'livreur_id' => ['nullable', 'exists:users,id'],
        ]);

        abort_unless((int) $deposit->pressing_id === (int) $request->user()->pressing_id, 403);

        $deposit->update([
            'delivery_zone_id' => $data['delivery_zone_id'] ?? $deposit->delivery_zone_id,
            'delivery_address' => $data['delivery_address'] ?? $deposit->delivery_address,
            'livreur_id' => $data['livreur_id'] ?? $deposit->livreur_id,
            'delivery_status' => $deposit->delivery_status ?: Deposit::DELIVERY_PENDING,
        ]);

        if ((int) $deposit->delivery_fee === 0) {
            $deposit->loadMissing('pressing');
            if ($deposit->pressing?->delivery_fee) {
                $deposit->update(['delivery_fee' => (int) $deposit->pressing->delivery_fee]);
            }
        }

        return $this->ok((new DepositResource($deposit->fresh(['client', 'units'])))->resolve());
    }
}
