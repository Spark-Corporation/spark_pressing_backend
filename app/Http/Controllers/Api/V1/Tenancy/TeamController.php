<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Actions\Staff\PersistStaffUser;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Access\PermissionMatrix;
use App\Support\Auth\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Organisation')]
class TeamController extends Controller
{
    /** Actions affichées dans « Activité récente » (événements sensibles des dernières 24 h). */
    private const SENSITIVE_ACTIONS = [
        'role.created', 'role.updated', 'user.created', 'user.updated', 'user.agencies_updated', 'user.disabled',
        'invitation.sent', 'invitation.accepted', 'invitation.revoked', 'device.created', 'device.authorized', 'device.revoked',
        'session.revoked', 'mfa.enabled', 'mfa.disabled',
    ];

    /** « Utilisateurs et équipe » : KPIs, compteurs d’onglets, taux MFA, activité sensible 24 h. */
    public function overview(Request $request): JsonResponse
    {
        $pressingId = $request->user()->pressing_id;
        $users = User::query()->where('pressing_id', $pressingId)->with(['roles:id,name', 'agencies:id'])->get();
        $active = $users->where('status', true);
        $managers = $active->filter(fn (User $u) => $u->hasRole('manager'));
        $invitations = UserInvitation::query()->where('pressing_id', $pressingId)->whereNull('accepted_at')->whereNull('revoked_at')->get();
        $pending = $invitations->filter(fn ($i) => $i->expires_at->isFuture());
        $connected = $active->filter(fn (User $u) => $u->last_login_at?->isToday() || $this->lastSeen($u)?->isToday());
        $mfaRequired = $active->filter(fn (User $u) => $u->mfa_required || $u->hasAnyRole((array) app(\App\Support\Settings\SettingsRegistry::class)->value($pressingId, 'security', 'mfa.required_roles', [])));

        return $this->ok([
            'kpis' => [
                'active_users' => ['value' => $active->count(), 'new_this_month' => $active->filter(fn ($u) => $u->created_at?->gte(now()->startOfMonth()))->count()],
                'managers' => ['value' => $managers->count(), 'multi_agency' => $managers->filter(fn ($u) => $u->agencies->count() > 1)->count()],
                'invitations' => ['value' => $pending->count(), 'expiring_soon' => $pending->filter(fn ($i) => $i->expires_at->lt(now()->addDay()))->count()],
                'connections_today' => ['value' => $connected->count(), 'team_percent' => $active->count() ? (int) round($connected->count() / $active->count() * 100) : 0],
            ],
            'mfa' => [
                'enrolled' => $active->whereNotNull('mfa_enabled_at')->count(),
                'percent' => $active->count() ? (int) round($active->whereNotNull('mfa_enabled_at')->count() / $active->count() * 100) : 0,
                'required' => $mfaRequired->count(),
            ],
            'tabs' => [
                ['code' => 'users', 'label' => 'Utilisateurs', 'count' => $users->count()],
                ['code' => 'invitations', 'label' => 'Invitations', 'count' => $pending->count()],
                ['code' => 'activity', 'label' => 'Activité récente', 'endpoint' => '/api/v1/team/activity'],
                ['code' => 'multi_agency', 'label' => 'Accès multi-agence', 'count' => $active->filter(fn ($u) => $u->agencies->count() > 1 || $u->canViewAllAgencies())->count()],
            ],
            'filters' => [
                'agencies' => Agency::query()->where('pressing_id', $pressingId)->orderBy('name')->get(['id', 'name']),
                'roles' => PermissionMatrix::rolesFor($pressingId)->map(fn ($r) => ['code' => $r->role->name, 'label' => $r->label])->values(),
                'statuses' => [['code' => 'active', 'label' => 'Actif'], ['code' => 'inactive', 'label' => 'Inactif']],
            ],
            'recent_activity' => $this->activity($pressingId, 24, 5),
        ]);
    }

    /** Annuaire paginé (`q`, `agency_id`, `role`, `status=active|inactive`, `period=day|week|month` sur la dernière connexion). */
    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'agency_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'period' => ['nullable', 'in:day,week,month'],
        ]);
        $agencyNames = Agency::query()->where('pressing_id', $request->user()->pressing_id)->pluck('name', 'id');
        $labels = PermissionMatrix::rolesFor($request->user()->pressing_id)->mapWithKeys(fn ($r) => [$r->role->name => $r->label]);

        $users = User::query()
            ->with(['roles:id,name', 'agencies:id,name'])
            ->where('pressing_id', $request->user()->pressing_id)
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('fullname', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
                ->orWhereHas('agencies', fn ($a) => $a->where('name', 'like', "%{$term}%"))))
            ->when($data['agency_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('agency_id', $id)->orWhereHas('agencies', fn ($a) => $a->where('agencies.id', $id))))
            ->when($data['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status'] === 'active'))
            ->when($data['period'] ?? null, fn ($q, $p) => $q->where('last_login_at', '>=', match ($p) { 'day' => now()->startOfDay(), 'week' => now()->subWeek(), default => now()->subMonth() }))
            ->orderBy('fullname')
            ->paginate(20);

        return $this->page($users, collect($users->items())->map(fn (User $u) => $this->row($u, $agencyNames, $labels))->all());
    }

    /** « Activité récente » de l’annuaire (événements sensibles, 24 h par défaut). */
    public function activity(int|Request $request, int $hours = 24, int $limit = 30): array|JsonResponse
    {
        if ($request instanceof Request) {
            $data = $request->validate(['hours' => ['nullable', 'integer', 'min:1', 'max:720']]);

            return $this->ok($this->activity($request->user()->pressing_id, $data['hours'] ?? 24));
        }

        $logs = AuditLog::query()->where('pressing_id', $request)->whereIn('action', self::SENSITIVE_ACTIONS)
            ->where('created_at', '>=', now()->subHours($hours))->latest('id')->limit($limit)->get();
        $actors = User::query()->whereIn('id', $logs->pluck('actor_id')->filter())->pluck('fullname', 'id');
        $subjects = User::query()->whereIn('id', $logs->where('subject_type', User::class)->pluck('subject_id'))->pluck('fullname', 'id');

        return $logs->map(function (AuditLog $l) use ($actors, $subjects) {
            $actor = $actors[$l->actor_id] ?? 'Système';
            $p = $l->payload ?? [];
            $subject = $subjects[$l->subject_id] ?? ($p['email'] ?? null);

            return [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'ago' => $l->created_at?->locale('fr')->diffForHumans(null, true),
                'action' => $l->action,
                'message' => match ($l->action) {
                    'role.created' => "{$actor} a créé le rôle ".($p['label'] ?? ''),
                    'role.updated' => "{$actor} a modifié le rôle ".($p['label'] ?? ''),
                    'user.agencies_updated' => "{$actor} a affecté {$subject} à ".implode(', ', $p['agencies'] ?? []),
                    'user.created' => "{$actor} a créé le compte de {$subject}",
                    'user.updated' => "{$actor} a modifié le compte de {$subject}",
                    'user.disabled' => "{$actor} a désactivé le compte de {$subject}",
                    'invitation.sent' => "{$actor} a invité {$subject}",
                    'invitation.accepted' => "{$subject} a accepté son invitation",
                    'invitation.revoked' => ($l->actor_id ? $actor : 'Système')." invitation {$subject} désactivée",
                    'device.created', 'device.authorized' => "{$actor} nouvel appareil autorisé ".($p['code'] ?? ''),
                    'device.revoked' => "{$actor} a révoqué l’appareil ".($p['code'] ?? ''),
                    'session.revoked' => "{$actor} a déconnecté une session",
                    'mfa.enabled' => "{$actor} a activé l’authentification renforcée",
                    'mfa.disabled' => "{$actor} a désactivé l’authentification renforcée",
                    default => $l->action,
                },
            ];
        })->values()->all();
    }

    public function invitations(Request $request): JsonResponse
    {
        $agencyNames = Agency::query()->where('pressing_id', $request->user()->pressing_id)->pluck('name', 'id');
        $rows = UserInvitation::query()->with('inviter:id,fullname')->where('pressing_id', $request->user()->pressing_id)->latest('id')->limit(100)->get()
            ->map(fn (UserInvitation $i) => $this->invitationRow($i, $agencyNames));

        return $this->ok($rows->values()->all());
    }

    /**
     * « Inviter un membre » : rôle, agences (multi-affectation), MFA exigée ; lien valable 72 h envoyé par e-mail.
     * `meta.sensitive_permissions` liste les droits sensibles du rôle choisi.
     */
    public function invite(Request $request): JsonResponse
    {
        $pressingId = $request->user()->pressing_id;
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'fullname' => ['nullable', 'string', 'max:191'],
            'role' => ['required', 'string', Rule::in(PermissionMatrix::rolesFor($pressingId)->map(fn ($r) => $r->role->name)->all())],
            'agency_ids' => ['required', 'array', 'min:1'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')->where('pressing_id', $pressingId)],
            'require_mfa' => ['nullable', 'boolean'],
        ]);
        $this->assertDelegable($request->user(), $data['role']);
        UserInvitation::query()->where('pressing_id', $pressingId)->where('email', $data['email'])->whereNull('accepted_at')->update(['revoked_at' => now()]);

        $invitation = UserInvitation::query()->create([
            ...$data,
            'pressing_id' => $pressingId,
            'require_mfa' => (bool) ($data['require_mfa'] ?? false),
            'token' => Str::random(48),
            'expires_at' => now()->addHours(UserInvitation::VALID_HOURS),
            'invited_by' => $request->user()->id,
        ]);
        $this->sendInvitation($invitation);
        AuditLog::record('invitation.sent', $invitation, ['email' => $invitation->email, 'role' => $invitation->role]);

        return $this->created(
            $this->invitationRow($invitation, Agency::query()->whereIn('id', $data['agency_ids'])->pluck('name', 'id')),
            ['sensitive_permissions' => $this->sensitiveOf($data['role'])],
        );
    }

    public function resendInvitation(Request $request, UserInvitation $invitation): JsonResponse
    {
        abort_unless((int) $invitation->pressing_id === (int) $request->user()->pressing_id, 404);
        abort_if($invitation->accepted_at !== null, 422, 'Invitation déjà acceptée.');
        $invitation->update(['token' => Str::random(48), 'expires_at' => now()->addHours(UserInvitation::VALID_HOURS), 'revoked_at' => null]);
        $this->sendInvitation($invitation);

        return $this->ok($this->invitationRow($invitation, Agency::query()->whereIn('id', $invitation->agency_ids)->pluck('name', 'id')));
    }

    public function revokeInvitation(Request $request, UserInvitation $invitation): JsonResponse
    {
        abort_unless((int) $invitation->pressing_id === (int) $request->user()->pressing_id, 404);
        $invitation->update(['revoked_at' => now()]);
        AuditLog::record('invitation.revoked', $invitation, ['email' => $invitation->email]);

        return $this->ok(['revoked' => true]);
    }

    /** Lien d’invitation (public) : `GET` pour afficher, `POST` avec `password` pour créer le compte. */
    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        return $this->ok([
            'email' => $invitation->email,
            'fullname' => $invitation->fullname,
            'role' => $invitation->role,
            'agencies' => Agency::query()->whereIn('id', $invitation->agency_ids)->pluck('name')->all(),
            'require_mfa' => $invitation->require_mfa,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function acceptInvitation(Request $request, string $token, PersistStaffUser $persist): JsonResponse
    {
        $invitation = $this->usable($token);
        $data = $request->validate([
            'fullname' => [$invitation->fullname ? 'nullable' : 'required', 'string', 'max:191'],
            'password' => ['required', 'string', 'min:12', 'regex:/[A-Z]/', 'regex:/\d/', 'confirmed'],
            'phone_number' => ['nullable', 'string', 'max:32'],
        ]);

        $user = DB::transaction(function () use ($invitation, $data, $persist) {
            $user = $persist->create([
                'fullname' => $data['fullname'] ?? $invitation->fullname,
                'email' => $invitation->email,
                'password' => $data['password'],
                'phone_number' => $data['phone_number'] ?? null,
                'pressing_id' => $invitation->pressing_id,
                'agency_id' => $invitation->agency_ids[0],
                'agency_ids' => $invitation->agency_ids,
                'role' => $invitation->role,
            ]);
            $user->update(['mfa_required' => $invitation->require_mfa, 'password_changed_at' => now()]);
            $invitation->update(['accepted_at' => now(), 'user_id' => $user->id]);

            return $user;
        });
        AuditLog::query()->create(['pressing_id' => $invitation->pressing_id, 'action' => 'invitation.accepted', 'subject_type' => User::class, 'subject_id' => $user->id, 'payload' => ['email' => $user->email], 'ip_address' => $request->ip()]);

        return $this->created(['user_id' => $user->id, 'email' => $user->email, 'mfa_required' => (bool) $user->mfa_required]);
    }

    /** Fiche membre : profil, agences, MFA, sessions. */
    public function showUser(Request $request, User $user): JsonResponse
    {
        $this->assertTeamUser($request, $user);
        $agencyNames = Agency::query()->where('pressing_id', $user->pressing_id)->pluck('name', 'id');
        $labels = PermissionMatrix::rolesFor((int) $user->pressing_id)->mapWithKeys(fn ($r) => [$r->role->name => $r->label]);

        return $this->ok($this->row($user->load(['roles:id,name', 'agencies:id,name']), $agencyNames, $labels) + [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'job_title' => $user->job_title,
            'employee_number' => $user->employee_number,
            'mfa_required' => (bool) $user->mfa_required,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'sessions' => $this->sessionsOf($user),
        ]);
    }

    public function updateUser(Request $request, User $user, PersistStaffUser $persist): JsonResponse
    {
        $this->assertTeamUser($request, $user);
        $pressingId = (int) $request->user()->pressing_id;
        $data = $request->validate([
            'fullname' => ['sometimes', 'string', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'employee_number' => ['nullable', 'string', 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::in(PermissionMatrix::rolesFor($pressingId)->map(fn ($r) => $r->role->name)->all())],
            'agency_id' => ['sometimes', 'integer', Rule::exists('agencies', 'id')->where('pressing_id', $pressingId)],
            'agency_ids' => ['sometimes', 'array', 'min:1'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')->where('pressing_id', $pressingId)],
            'mfa_required' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['role'])) {
            $this->assertDelegable($request->user(), $data['role']);
        }
        $persist->update($user, $data + ['pressing_id' => $pressingId]);
        if (isset($data['agency_ids'])) {
            $home = $data['agency_id'] ?? $user->agency_id ?? $data['agency_ids'][0];
            $sync = collect($data['agency_ids'])->mapWithKeys(fn ($id) => [$id => ['is_home' => (int) $id === (int) $home]])->all();
            $user->agencies()->sync($sync);
            $user->update(['agency_id' => $home]);
            AuditLog::record('user.agencies_updated', $user, ['agencies' => Agency::query()->whereIn('id', $data['agency_ids'])->pluck('name')->all()]);
        }
        AuditLog::record('user.updated', $user, ['email' => $user->email]);

        return $this->showUser($request, $user->fresh(['roles', 'agencies']));
    }

    public function disableUser(Request $request, User $user): JsonResponse
    {
        $this->assertTeamUser($request, $user);
        abort_if($user->id === $request->user()->id, 422, 'Vous ne pouvez pas désactiver votre propre compte.');
        $user->update(['status' => false]);
        $user->tokens()->delete();
        AuditLog::record('user.disabled', $user, ['email' => $user->email]);

        return $this->ok(['id' => $user->id, 'status' => 'inactive']);
    }

    public function enableUser(Request $request, User $user): JsonResponse
    {
        $this->assertTeamUser($request, $user);
        $user->update(['status' => true]);
        AuditLog::record('user.updated', $user, ['email' => $user->email, 'enabled' => true]);

        return $this->ok(['id' => $user->id, 'status' => 'active']);
    }

    public function sessions(Request $request, User $user): JsonResponse
    {
        $this->assertTeamUser($request, $user);

        return $this->ok($this->sessionsOf($user));
    }

    public function revokeSession(Request $request, User $user, int $tokenId): JsonResponse
    {
        $this->assertTeamUser($request, $user);
        $token = PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->whereKey($tokenId)->firstOrFail();
        $token->delete();
        AuditLog::record('session.revoked', $user, ['token_id' => $tokenId]);

        return $this->ok(['revoked' => true]);
    }

    /** Démarre l’enrôlement MFA (TOTP) pour l’utilisateur courant. */
    public function setupMfa(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = Totp::secret();
        $user->forceFill(['mfa_secret' => $secret])->save();

        return $this->ok([
            'secret' => $secret,
            'otpauth_url' => Totp::uri($secret, $user->email),
            'manual_entry' => $secret,
        ]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string']]);
        if (! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }
        $codes = Totp::recoveryCodes();
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_recovery_codes' => $codes])->save();
        AuditLog::record('mfa.enabled', $user);

        return $this->ok(['enabled' => true, 'recovery_codes' => $codes]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }
        if ($user->mfa_enabled_at && ! empty($data['code']) && $user->mfa_secret && ! Totp::verify($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }
        $user->forceFill(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enabled_at' => null])->save();
        AuditLog::record('mfa.disabled', $user);

        return $this->ok(['enabled' => false]);
    }

    public function row(User $u, Collection $agencyNames, Collection $labels): array
    {
        $role = $u->roles->first()?->name;
        $agencies = $u->agencies->pluck('id')->push($u->agency_id)->filter()->unique()->values();
        $home = $agencyNames[$u->agency_id] ?? null;
        $last = $u->last_login_at ?? $this->lastSeen($u);

        return [
            'id' => $u->id,
            'fullname' => $u->displayName(),
            'initials' => collect(explode(' ', (string) $u->displayName()))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''),
            'email' => $u->email,
            'phone_number' => $u->phone_number,
            'role' => $role,
            'role_label' => $labels[$role] ?? $role,
            'agency_ids' => $agencies->all(),
            'agencies_label' => $u->canViewAllAgencies() ? 'Toutes les agences' : ($agencies->count() > 1 ? "{$home} + ".($agencies->count() - 1) : $home),
            'status' => $u->status ? 'active' : 'inactive',
            'status_label' => $u->status ? 'Actif' : 'Inactif',
            'mfa_enabled' => $u->mfa_enabled_at !== null,
            'last_login_at' => $last?->toIso8601String(),
            'last_login_label' => $last ? match (true) {
                $last->isToday() => 'Aujourd’hui, '.$last->format('H:i'),
                $last->isYesterday() => 'Hier, '.$last->format('H:i'),
                default => $last->locale('fr')->isoFormat('D MMM YYYY'),
            } : 'Jamais',
        ];
    }

    private function lastSeen(User $u): ?\Carbon\CarbonInterface
    {
        $at = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $u->id)->max('last_used_at');

        return $at ? now()->parse($at) : null;
    }

    private function invitationRow(UserInvitation $i, Collection $agencyNames): array
    {
        $status = $i->status();

        return [
            'id' => $i->id,
            'email' => $i->email,
            'fullname' => $i->fullname,
            'role' => $i->role,
            'agencies' => collect($i->agency_ids)->map(fn ($id) => $agencyNames[$id] ?? null)->filter()->values()->all(),
            'require_mfa' => $i->require_mfa,
            'status' => $status,
            'status_label' => ['accepted' => 'Acceptée', 'revoked' => 'Annulée', 'expired' => 'Expirée', 'expiring' => 'Expire bientôt', 'pending' => 'En attente'][$status],
            'expires_at' => $i->expires_at->toIso8601String(),
            'invited_by' => $i->inviter?->fullname,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function sendInvitation(UserInvitation $invitation): void
    {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/invitation/'.$invitation->token;
        Mail::raw(
            "Bonjour,\n\nVous êtes invité(e) à rejoindre l’équipe SPARK PRESSING. Ce lien est valable 72 heures :\n{$url}\n",
            fn ($m) => $m->to($invitation->email)->subject('Invitation SPARK PRESSING'),
        );
    }

    private function usable(string $token): UserInvitation
    {
        $invitation = UserInvitation::query()->where('token', $token)->first();
        if (! $invitation || ! in_array($invitation->status(), ['pending', 'expiring'], true)) {
            throw ValidationException::withMessages(['token' => 'Invitation invalide ou expirée.']);
        }

        return $invitation;
    }

    /** Un utilisateur ne peut attribuer qu’un rôle dont il détient tous les droits fins. */
    private function assertDelegable(User $user, string $role): void
    {
        $roleModel = \Spatie\Permission\Models\Role::findByName($role, 'web');
        $missing = array_diff(PermissionMatrix::roleActions($roleModel->load('permissions')), PermissionMatrix::delegable($user));
        if ($missing) {
            throw ValidationException::withMessages(['role' => 'Ce rôle dépasse vos propres droits.']);
        }
    }

    private function sensitiveOf(string $role): array
    {
        $catalog = PermissionMatrix::actions();
        $roleModel = \Spatie\Permission\Models\Role::findByName($role, 'web')->load('permissions');

        return collect(PermissionMatrix::roleActions($roleModel))->filter(fn ($a) => $catalog[$a]['sensitive'])
            ->map(fn ($a) => config("permission_matrix.domains.{$catalog[$a]['domain']}.label").' · '.$catalog[$a]['label'])->values()->all();
    }

    private function assertTeamUser(Request $request, User $user): void
    {
        abort_unless((int) $user->pressing_id === (int) $request->user()->pressing_id, 404);
    }

    private function sessionsOf(User $user): array
    {
        return PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $user->id)
            ->latest('id')->limit(20)->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'ip_address' => $t->ip_address,
                'user_agent' => $t->user_agent,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'current' => $t->id === optional(auth()->user()?->currentAccessToken())->id,
            ])->all();
    }
}
