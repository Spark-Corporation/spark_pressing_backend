<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Support\Deposits\DepositPresenter;
use App\Support\Portal\ClientAppPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/** Application mobile client (Figma 14). */
#[Group('Espace client')]
class ClientAppController extends Controller
{
    public function __construct(private readonly ClientAppPresenter $presenter) {}

    /** Accueil : dépôt actif et étapes, prochain retrait, reste à payer, points, actions rapides, dernière notification. */
    public function home(Request $request): JsonResponse
    {
        return $this->ok($this->presenter->home($this->client($request)));
    }

    /** « Mes dépôts » : `q` (code), `status=in_progress|ready|retrieved`, compteurs des filtres. */
    public function deposits(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:40'], 'status' => ['nullable', 'in:in_progress,ready,retrieved']]);
        $client = $this->client($request);
        $base = fn () => Deposit::query()->withoutGlobalScopes()->where('client_id', $client->id);
        $ready = [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED];
        $scope = fn ($q, ?string $status) => match ($status) {
            'retrieved' => $q->where('status', false),
            'ready' => $q->where('status', true)->whereIn('etat', $ready),
            'in_progress' => $q->where('status', true)->whereNotIn('etat', $ready),
            default => $q,
        };

        $page = $scope($base(), $data['status'] ?? null)
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('code', 'like', "%{$term}%"))
            ->orderByDesc('deposit_date')->paginate(20);

        return $this->page($page, collect($page->items())->map(fn (Deposit $d) => $this->presenter->depositCard($d))->all(), [
            'filters' => collect(['all' => 'Tous', 'in_progress' => 'En cours', 'ready' => 'Prêts', 'retrieved' => 'Retirés'])
                ->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'count' => $scope($base(), $code === 'all' ? null : $code)->count()])->values()->all(),
        ]);
    }

    /** « Suivi du dépôt ». */
    public function deposit(Request $request, int $deposit, DepositPresenter $presenter): JsonResponse
    {
        $model = Deposit::query()->withoutGlobalScopes()->with(['units', 'agency:id,name,contact,address', 'client'])
            ->where('client_id', $this->client($request)->id)->findOrFail($deposit);

        return $this->ok($this->presenter->tracking($model, $presenter));
    }

    /** « Ma fidélité ». */
    public function loyalty(Request $request): JsonResponse
    {
        return $this->ok($this->presenter->loyalty($this->client($request)));
    }

    /** « Profil et sécurité ». */
    public function profile(Request $request): JsonResponse
    {
        return $this->ok($this->presenter->profile($this->client($request)));
    }

    /** « Enregistrer les modifications » et préférences de communication. */
    public function updateProfile(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validate([
            'fullname' => ['sometimes', 'string', 'max:191'],
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'agency_id' => ['sometimes', 'integer', Rule::exists('agencies', 'id')->where('pressing_id', $client->pressing_id)],
            'communication' => ['sometimes', 'array'],
            'communication.sms' => ['sometimes', 'boolean'],
            'communication.email' => ['sometimes', 'boolean'],
            'communication.personalized_offers' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['phone_number']) && $data['phone_number'] !== $client->phone_number) {
            $data['phone_verified_at'] = null;
        }
        if (isset($data['communication'])) {
            $c = $data['communication'];
            $data += array_filter([
                'consent_sms' => $c['sms'] ?? null,
                'consent_email' => $c['email'] ?? null,
                'consent_marketing' => $c['personalized_offers'] ?? null,
            ], fn ($v) => $v !== null) + ['consent_updated_at' => now()];
            unset($data['communication']);
        }
        $client->update($data);

        return $this->ok($this->presenter->profile($client->fresh()));
    }

    /** « Changer le mot de passe » : 8 caractères minimum, une majuscule et un chiffre. */
    public function password(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/\d/', 'confirmed'],
        ], ['password.regex' => 'Le mot de passe doit contenir une majuscule et un chiffre.']);
        if (! Hash::check($data['current_password'], (string) $client->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }
        $client->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();

        return $this->ok(['updated' => true]);
    }

    /** « Déconnecter les autres appareils ». */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return $this->ok(['revoked' => $client->tokens()->where('id', '!=', $client->currentAccessToken()?->id)->delete()]);
    }

    /** « Télécharger mes données » (JSON). */
    public function export(Request $request): Response
    {
        $client = $this->client($request);
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $client->only(['code', 'fullname', 'email', 'phone_number', 'address', 'loyalty_points', 'consent_sms', 'consent_email', 'consent_marketing', 'created_at']),
            'deposits' => Deposit::query()->withoutGlobalScopes()->with('units:id,deposit_id,designation,quantity,line_total')->where('client_id', $client->id)
                ->get(['id', 'code', 'deposit_date', 'retrieve_date', 'retrieved_at', 'total', 'advanced', 'left_to_pay']),
            'loyalty' => LoyaltyLedger::query()->withoutGlobalScopes()->where('client_id', $client->id)->get(['type', 'points', 'reason', 'created_at']),
        ];
        AuditLog::query()->create(['pressing_id' => $client->pressing_id, 'actor_type' => Client::class, 'actor_id' => $client->id, 'action' => 'client.data_exported', 'subject_type' => Client::class, 'subject_id' => $client->id, 'ip_address' => $request->ip()]);

        return response()->json($payload, 200, ['Content-Disposition' => 'attachment; filename="mes-donnees-spark.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** « Supprimer mon compte » : archive le compte, coupe les consentements et toutes les sessions. */
    public function deleteAccount(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], (string) $client->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }
        if (Deposit::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('status', true)->exists()) {
            throw ValidationException::withMessages(['account' => 'Retirez vos dépôts en cours avant de supprimer votre compte.']);
        }
        $client->forceFill(['archived_at' => now(), 'status' => false, 'consent_sms' => false, 'consent_email' => false, 'consent_marketing' => false])->save();
        $client->tokens()->delete();
        AuditLog::query()->create(['pressing_id' => $client->pressing_id, 'actor_type' => Client::class, 'actor_id' => $client->id, 'action' => 'client.account_deleted', 'subject_type' => Client::class, 'subject_id' => $client->id, 'ip_address' => $request->ip()]);

        return $this->ok(['deleted' => true]);
    }

    private function client(Request $request): Client
    {
        $client = $request->user();
        abort_unless($client instanceof Client, 403);

        return $client;
    }
}
