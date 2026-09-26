<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Actions\Client\RegisterClient;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Clients')]
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $days = max(1, (int) $request->get('inactive_days', 30));

        $clients = Client::query()
            ->withCount('deposits')
            ->when($q, function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('fullname', 'like', "%{$q}%")
                        ->orWhere('phone_number', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('sponsor_code', 'like', "%{$q}%");
                });
            })
            ->when($request->boolean('inactive'), function ($query) use ($days) {
                $query->where(function ($inner) use ($days) {
                    $inner->whereNull('last_deposit_at')
                        ->orWhere('last_deposit_at', '<', now()->subDays($days));
                });
            })
            ->when($request->boolean('loyal'), fn ($query) => $query->whereHas('loyalGroups'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->boolean('status')))
            ->latest()
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($clients, ClientResource::collection($clients)->resolve());
    }

    public function inactive(Request $request): JsonResponse
    {
        return $this->index($request->merge(['inactive' => 1]));
    }

    public function store(Request $request, RegisterClient $register): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:191'],
            'phone_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8'],
            'sponsor_code' => ['nullable', 'string', 'max:12'],
        ]);

        $client = $register->handle($data + [
            'pressing_id' => $user->pressing_id,
            'agency_id' => $user->agency_id,
        ]);

        AuditLog::record('client.created', $client);

        return $this->created((new ClientResource($client))->resolve());
    }

    public function show(Client $client): JsonResponse
    {
        $client->loadCount(['deposits', 'referrals']);
        $client->load('loyalGroups:id,name,rate_percent');

        return $this->ok((new ClientResource($client))->resolve());
    }

    public function state(Client $client): JsonResponse
    {
        $totals = Deposit::query()
            ->where('client_id', $client->id)
            ->selectRaw('count(*) as deposits,
                coalesce(sum(total),0) as total,
                coalesce(sum(advanced),0) as advanced,
                coalesce(sum(discount),0) as discount,
                coalesce(sum(left_to_pay),0) as left_to_pay,
                coalesce(sum(case when status = 1 then 1 else 0 end),0) as open_deposits')
            ->first();

        $recent = Deposit::query()
            ->withSum('units as items_count', 'quantity')
            ->where('client_id', $client->id)
            ->latest('deposit_date')
            ->limit(10)
            ->get();

        return $this->ok([
            'client' => (new ClientResource($client))->resolve(),
            'deposits' => (int) ($totals->deposits ?? 0),
            'open_deposits' => (int) ($totals->open_deposits ?? 0),
            'total' => (int) ($totals->total ?? 0),
            'advanced' => (int) ($totals->advanced ?? 0),
            'discount' => (int) ($totals->discount ?? 0),
            'left_to_pay' => (int) ($totals->left_to_pay ?? 0),
            'recent' => DepositResource::collection($recent)->resolve(),
        ]);
    }

    public function referrals(Client $client): JsonResponse
    {
        $items = $client->referrals()->latest()->paginate(50);

        return $this->page($items, ClientResource::collection($items)->resolve());
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'fullname' => ['sometimes', 'string', 'max:191'],
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'status' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $client->update($data);

        return $this->ok((new ClientResource($client))->resolve());
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();
        AuditLog::record('client.deleted', $client);

        return $this->ok(['deleted' => true]);
    }
}
