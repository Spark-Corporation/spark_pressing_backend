<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Actions\Client\RegisterClient;
use App\Actions\Loyalty\RecordLoyalty;
use App\Actions\Wallet\RecordWallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deposit;
use App\Models\Pressing;
use App\Support\Crm\ClientPresenter;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Clients')]
class ClientController extends Controller
{
    public function __construct(private readonly ClientPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $days = max(1, (int) $request->get('inactive_days', 30));

        $clients = Client::query()
            ->with(['agency:id,name'])
            ->tap(fn ($query) => ClientPresenter::withRowAggregates($query))
            ->tap(fn ($query) => ClientPresenter::applyFilters($query, $request->only(['tier', 'crm_status', 'agency_id', 'period'])))
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

        return $this->page(
            $clients,
            collect($clients->items())->map(fn (Client $client) => $this->presenter->row($client))->all(),
            $this->presenter->overview()
        );
    }

    /** « Exporter » — CSV du répertoire (mêmes filtres que la liste). */
    public function export(Request $request): StreamedResponse
    {
        $rows = Client::query()
            ->with(['agency:id,name'])
            ->tap(fn ($query) => ClientPresenter::withRowAggregates($query))
            ->tap(fn ($query) => ClientPresenter::applyFilters($query, $request->only(['tier', 'crm_status', 'agency_id', 'period'])))
            ->latest()
            ->limit(10000)
            ->get()
            ->map(fn (Client $client) => $this->presenter->row($client));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Code', 'Client', 'Téléphone', 'Agence', 'Dernier dépôt', 'Fréquence / mois', 'Valeur client', 'Points', 'Groupe', 'Statut'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['code'], $r['fullname'], $r['phone_number'], $r['agency']['name'] ?? null, $r['last_deposit_at'],
                    $r['frequency_per_month'], $r['lifetime_value'], $r['loyalty_points'], $r['tier']['label'], $r['crm_status']['label'],
                ], ';');
            }
            fclose($out);
        }, 'clients-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** « Contrôle des doublons » par téléphone (principal ou secondaire) ou e-mail. */
    public function duplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'except_id' => ['nullable', 'integer'],
        ]);

        $matches = Client::query()
            ->with('agency:id,name')
            ->when($data['except_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))
            ->where(function ($q) use ($data) {
                $q->whereRaw('1 = 0');
                if (! empty($data['phone'])) {
                    $q->orWhere('phone_number', $data['phone'])->orWhere('fixe_number', $data['phone']);
                }
                if (! empty($data['email'])) {
                    $q->orWhere('email', $data['email']);
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'fullname' => $c->fullname,
                'phone_number' => $c->phone_number,
                'agency' => $c->agency?->name,
            ]);

        return $this->ok(['has_duplicates' => $matches->isNotEmpty(), 'matches' => $matches]);
    }

    public function inactive(Request $request): JsonResponse
    {
        return $this->index($request->merge(['inactive' => 1]));
    }

    public function store(Request $request, RegisterClient $register): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'fullname' => ['required_without:last_name', 'nullable', 'string', 'max:191'],
            'last_name' => ['required_without:fullname', 'nullable', 'string', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:191'],
            'phone_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8'],
            'sponsor_code' => ['nullable', 'string', 'max:12'],
        ] + self::profileRules());

        $data = self::normalize($data);
        $client = $register->handle($data + [
            'pressing_id' => $user->pressing_id,
            'agency_id' => $data['agency_id'] ?? $user->agency_id,
        ]);

        if (! empty($data['note'])) {
            $client->notes()->create(['pressing_id' => $client->pressing_id, 'user_id' => $user->id, 'body' => $data['note']]);
        }

        AuditLog::record('client.created', $client);

        return $this->created($this->presenter->profile($client->fresh()));
    }

    public function show(Client $client): JsonResponse
    {
        $client->loadCount(['deposits', 'referrals']);
        $client->load('loyalGroups:id,name,rate_percent', 'agency:id,name');
        $client->loadSum('deposits as lifetime_value', 'total');
        $client->loadMin('deposits as first_deposit_at', 'deposit_date');

        return $this->ok($this->presenter->profile($client));
    }

    public function notes(Client $client): JsonResponse
    {
        return $this->ok($client->notes()->with('author:id,fullname')->get()->map(fn (ClientNote $n) => $this->presenter->note($n))->all());
    }

    /** « Ajouter une note » — visible uniquement par les équipes. */
    public function storeNote(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $note = $client->notes()->create([
            'pressing_id' => $client->pressing_id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return $this->created($this->presenter->note($note->load('author:id,fullname')));
    }

    /** « Archiver le client » — l’historique reste accessible en lecture seule. */
    public function archive(Client $client): JsonResponse
    {
        $client->update(['archived_at' => now(), 'status' => false]);
        AuditLog::record('client.archived', $client);

        return $this->ok($this->presenter->row($client->fresh()));
    }

    public function restore(Client $client): JsonResponse
    {
        $client->update(['archived_at' => null, 'status' => true]);
        AuditLog::record('client.restored', $client);

        return $this->ok($this->presenter->row($client->fresh()));
    }

    /** « Convertir en remise » — points → crédit portefeuille utilisable au prochain dépôt. */
    public function convertPoints(Request $request, Client $client, RecordLoyalty $loyalty, RecordWallet $wallet): JsonResponse
    {
        $minimum = (int) app(SettingsRegistry::class)->value($client->pressing_id, 'loyalty', 'program.minimum_points', 0);
        $data = $request->validate(['points' => ['required', 'integer', 'min:'.max(1, $minimum)]]);
        $pressing = Pressing::query()->findOrFail($client->pressing_id);

        $amount = DB::transaction(function () use ($client, $pressing, $data, $loyalty, $wallet) {
            $amount = $loyalty->redeem($client, $pressing, (int) $data['points']);
            if ($amount === 0) {
                throw ValidationException::withMessages(['points' => 'Points insuffisants ou seuil non atteint.']);
            }
            $wallet->credit($client, $amount, 'Conversion de points fidélité');

            return $amount;
        });

        AuditLog::record('client.points_converted', $client, ['points' => (int) $data['points'], 'amount' => $amount]);

        return $this->ok([
            'converted_points' => (int) $data['points'],
            'amount' => $amount,
            'loyalty' => $this->presenter->loyalty($client->fresh()),
            'wallet_balance' => (int) $client->fresh()->wallet_balance,
        ]);
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
            'last_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'status' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ] + self::profileRules());

        $data = self::normalize($data + array_filter([
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
        ], fn ($v) => $v !== null), $client);
        unset($data['note']);
        $client->update($data);

        return $this->ok($this->presenter->profile($client->fresh()));
    }

    /** Champs « Relation client », consentements, préférences (création et modification). */
    private static function profileRules(): array
    {
        return [
            'phone_secondary' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'contact_preference' => ['nullable', 'in:'.implode(',', array_keys(Client::CONTACT_PREFERENCES))],
            'loyalty_tier' => ['nullable', 'in:'.implode(',', array_keys(config('spark.loyalty_tiers')))],
            'consent_sms' => ['sometimes', 'boolean'],
            'consent_email' => ['sometimes', 'boolean'],
            'pickup_slot' => ['nullable', 'string', 'max:191'],
            'favorite_treatment' => ['nullable', 'string', 'max:191'],
            'instructions' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private static function normalize(array $data, ?Client $client = null): array
    {
        if (array_key_exists('phone_secondary', $data)) {
            $data['fixe_number'] = $data['phone_secondary'];
            unset($data['phone_secondary']);
        }
        if (! empty($data['last_name']) && ! array_key_exists('fullname', $data)) {
            $data['fullname'] = trim(($data['first_name'] ?? '').' '.$data['last_name']);
        }
        $consentChanged = (array_key_exists('consent_sms', $data) && (bool) $data['consent_sms'] !== (bool) $client?->consent_sms)
            || (array_key_exists('consent_email', $data) && (bool) $data['consent_email'] !== (bool) $client?->consent_email);
        if ($consentChanged) {
            $data['consent_updated_at'] = now();
        }

        return $data;
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();
        AuditLog::record('client.deleted', $client);

        return $this->ok(['deleted' => true]);
    }
}
