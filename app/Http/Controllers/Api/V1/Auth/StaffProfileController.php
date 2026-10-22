<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\Access\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Laravel\Sanctum\PersonalAccessToken;

/** Écran Figma « 11 — Profil et sécurité staff » (compte courant). */
#[Group('Authentification')]
class StaffProfileController extends Controller
{
    private const PREFERENCES = [
        'language' => 'fr-CM',
        'timezone' => 'Africa/Douala',
        'daily_summary' => true,
        'workshop_sound_alerts' => false,
        'whatsapp_notifications' => true,
    ];

    /** Identité, rôle et affectations (lecture seule), mot de passe, MFA, sessions, appareils, préférences. */
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($this->staff($request), $request));
    }

    /** « Identité et coordonnées » et « Préférences » (le rôle et les affectations restent gérés par l’administrateur). */
    public function update(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:191'],
            'preferences' => ['sometimes', 'array'],
            'preferences.language' => ['sometimes', 'string', 'max:10'],
            'preferences.timezone' => ['sometimes', 'timezone'],
            'preferences.daily_summary' => ['sometimes', 'boolean'],
            'preferences.workshop_sound_alerts' => ['sometimes', 'boolean'],
            'preferences.whatsapp_notifications' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['preferences'])) {
            $data['preferences'] = array_merge(self::PREFERENCES, $user->preferences ?? [], array_intersect_key($data['preferences'], self::PREFERENCES));
        }
        $user->fill($data);
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $user->fullname = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->fullname;
            $user->name = $user->fullname;
        }
        $user->save();

        return $this->ok($this->payload($user->fresh(), $request));
    }

    /** « Changer le mot de passe » : 12 caractères minimum, une majuscule et un chiffre. */
    public function password(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'regex:/[A-Z]/', 'regex:/\d/', 'confirmed', 'different:current_password'],
        ], ['password.regex' => 'Le mot de passe doit contenir une majuscule et un chiffre.']);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }
        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        AuditLog::record('user.password_changed', $user);

        return $this->ok(['updated' => true, 'password_changed_at' => $user->password_changed_at->toIso8601String()]);
    }

    /** « Déconnecter » une session (jeton Sanctum) du compte courant. */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user = $this->staff($request);
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        abort_if((int) $token->id === (int) $user->currentAccessToken()?->id, 422, 'Utilisez la déconnexion pour la session actuelle.');
        $token->delete();
        AuditLog::record('session.revoked', $user, ['token_id' => $tokenId]);

        return $this->ok(['revoked' => true]);
    }

    /** « Déconnecter les autres ». */
    public function revokeOthers(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $count = $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        AuditLog::record('session.revoked', $user, ['count' => $count]);

        return $this->ok(['revoked' => $count]);
    }

    private function payload(User $user, Request $request): array
    {
        $user->loadMissing(['roles:id,name', 'agencies:id,name']);
        $role = $user->roles->first()?->name;
        $label = $role ? (PermissionMatrix::rolesFor((int) $user->pressing_id)->first(fn ($r) => $r->role->name === $role)?->label ?? $role) : null;
        $home = Agency::query()->whereKey($user->agency_id)->value('name');
        $currentId = $user->currentAccessToken()?->id;

        return [
            'identity' => [
                'id' => $user->id,
                'first_name' => $user->first_name ?? strtok((string) $user->fullname, ' '),
                'last_name' => $user->last_name ?? trim(substr((string) $user->fullname, strlen((string) strtok((string) $user->fullname, ' ')))),
                'fullname' => $user->displayName(),
                'initials' => collect(explode(' ', (string) $user->displayName()))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''),
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'job_title' => $user->job_title ?? $label,
                'employee_number' => $user->employee_number ?? sprintf('STF-%04d', $user->id),
                'picture' => $user->picture,
                'status' => $user->status ? 'active' : 'inactive',
                'status_label' => $user->status ? 'Compte actif' : 'Compte désactivé',
            ],
            'role' => [
                'code' => $role,
                'label' => $label,
                'managed_by_admin' => true,
                'home_agency' => $user->agency_id ? ['id' => $user->agency_id, 'name' => $home] : null,
                'other_agencies' => $user->agencies->where('id', '!=', $user->agency_id)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
            ],
            'password' => [
                'changed_at' => $user->password_changed_at?->toIso8601String(),
                'rules' => ['min_length' => 12, 'uppercase' => true, 'digit' => true],
            ],
            'mfa' => [
                'enabled' => $user->mfa_enabled_at !== null,
                'required' => (bool) $user->mfa_required,
                'method' => $user->mfa_enabled_at ? 'totp' : null,
                'configured_at' => $user->mfa_enabled_at?->toIso8601String(),
                'recovery_codes_left' => count($user->mfa_recovery_codes ?? []),
            ],
            'sessions' => $user->tokens()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('last_used_at')->get()
                ->map(fn (PersonalAccessToken $t) => [
                    'id' => $t->id,
                    'device' => self::device((string) $t->user_agent),
                    'ip_address' => $t->ip_address,
                    'last_used_at' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
                    'current' => (int) $t->id === (int) $currentId,
                    'expires_at' => $t->expires_at?->toIso8601String(),
                ])->values()->all(),
            'authorized_devices' => PosDevice::query()->withoutGlobalScopes()->where('responsible_id', $user->id)->get()
                ->map(fn (PosDevice $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'label' => $d->label ?? $d->code,
                    'status' => $d->effectiveStatus(),
                    'expires_at' => $d->expires_at?->toIso8601String(),
                    'managed_by_organization' => true,
                ])->values()->all(),
            'preferences' => array_merge(self::PREFERENCES, $user->preferences ?? []),
        ];
    }

    /** Libellé lisible d’un user-agent (« iPhone », « Windows · Chrome »…). */
    private static function device(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => null,
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };

        return collect([$os, $browser])->filter()->implode(' · ') ?: 'Appareil inconnu';
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
