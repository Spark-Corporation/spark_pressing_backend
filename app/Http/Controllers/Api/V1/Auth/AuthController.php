<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Client\RegisterClient;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\UserResource;
use App\Models\Admin;
use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Auth\LoginThrottle;
use App\Support\Auth\PasswordResetCodes;
use App\Support\Auth\Totp;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Authentification')]
class AuthController extends Controller
{
    private const ROLE_LABELS = [
        'admin' => 'Administrateur',
        'manager' => 'Manager',
        'caissier' => 'Caissier',
        'laveur' => 'Laveur',
        'classeur' => 'Classeur',
        'livreur' => 'Livreur',
    ];

    #[Unauthenticated]
    #[Endpoint('Connexion staff', 'Identifiant (e-mail ou téléphone) + mot de passe, agence/contexte optionnel. Après 5 échecs : 429 pendant 15 minutes.')]
    #[BodyParam('login', 'string', 'E-mail ou téléphone du compte staff (ou `email`).', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('password', 'string', 'Mot de passe.', required: true, example: 'password')]
    #[BodyParam('agency_id', 'integer', 'Contexte d’agence choisi (doit faire partie de `contexts`).', required: false, example: 1)]
    #[BodyParam('remember', 'boolean', '« Se souvenir de moi » : jeton valable 30 jours au lieu de 12 h.', required: false, example: true)]
    #[Response([
        'data' => [
            'token' => '1|abcExampleSanctumToken',
            'token_type' => 'Bearer',
            'expires_at' => '2026-10-22T22:00:00+00:00',
            'actor' => 'staff',
            'user' => [
                'id' => 1,
                'fullname' => 'Claire Caissier',
                'email' => 'caissier@elegance.local',
                'phone_number' => null,
                'pressing_id' => 1,
                'agency_id' => 1,
                'status' => true,
                'roles' => ['caissier'],
                'agency' => [
                    'id' => 1,
                    'pressing_id' => 1,
                    'name' => 'Akwa',
                    'address' => 'Douala, Akwa',
                    'contact' => '+237600000001',
                    'country_code' => 'CM',
                    'currency' => 'XAF',
                    'code_prefix' => 'AK',
                    'code_suffix' => 'EL',
                    'status' => true,
                ],
                'agencies' => [
                    [
                        'id' => 1,
                        'pressing_id' => 1,
                        'name' => 'Akwa',
                        'address' => 'Douala, Akwa',
                        'contact' => '+237600000001',
                        'country_code' => 'CM',
                        'currency' => 'XAF',
                        'code_prefix' => 'AK',
                        'code_suffix' => 'EL',
                        'status' => true,
                    ],
                ],
                'pressing' => [
                    'id' => 1,
                    'name' => 'Élégance Pressing',
                    'details' => 'Enseigne de démonstration',
                    'status' => true,
                    'pricing_mode' => 'piece',
                    'workflow_laveur_enabled' => true,
                    'workflow_classeur_enabled' => true,
                    'qr_labels_enabled' => false,
                    'block_retrieve_if_unpaid' => true,
                    'reporting_currency' => null,
                    'collection_fee' => 0,
                    'delivery_fee' => 0,
                    'loyalty_points_rate' => 0,
                    'hours_classic' => null,
                    'hours_express' => null,
                    'hours_repass' => null,
                ],
                'can_view_all_agencies' => false,
                'contexts' => [
                    ['agency_id' => 1, 'agency' => 'Akwa', 'role' => 'caissier', 'label' => 'Akwa • Caissier', 'is_home' => true],
                ],
                'active_agency_id' => 1,
            ],
        ],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Succès')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Les données sont invalides.', 'attempts_remaining' => 3, 'max_attempts' => 5],
        'errors' => [
            ['code' => 'validation', 'field' => 'login', 'detail' => 'Identifiants invalides.'],
        ],
    ], status: 422, description: 'Identifiants invalides')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Trop de tentatives. Accès limité pendant 15 minutes.', 'retry_after' => 840, 'attempts_remaining' => 0],
        'errors' => [
            ['code' => 'locked', 'detail' => 'Réessayez dans 840 secondes.'],
        ],
    ], status: 429, description: 'Compte verrouillé après 5 échecs')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Compte désactivé.'],
        'errors' => [
            ['code' => 'error', 'detail' => 'Compte désactivé.'],
        ],
    ], status: 403, description: 'Compte désactivé')]
    public function staffLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:191'],
            'email' => ['required_without:login', 'nullable', 'email'],
            'password' => ['required', 'string'],
            'agency_id' => ['nullable', 'integer'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identifier = $credentials['login'] ?? $credentials['email'];
        $user = User::query()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('phone_number', $identifier))
            ->first();

        if ($user && isset($credentials['agency_id']) && Hash::check($credentials['password'], $user->password)
            && ! in_array((int) $credentials['agency_id'], $user->allowedAgencyIds(), true)) {
            throw ValidationException::withMessages(['agency_id' => 'Agence non autorisée pour ce compte.']);
        }

        return $this->issueToken($user, $credentials['password'], 'staff', function (User $user) use ($credentials) {
            $user->load(['agency', 'pressing', 'roles', 'agencies']);

            return (new UserResource($user))->resolve() + [
                'contexts' => $this->contexts($user),
                'active_agency_id' => (int) ($credentials['agency_id'] ?? $user->agency_id) ?: null,
            ];
        }, $identifier, $request->boolean('remember'), isset($credentials['login']) ? 'login' : 'email');
    }

    #[Unauthenticated]
    #[Endpoint('Connexion client', 'Login par email ou téléphone. Retourne un token Bearer et le profil client.')]
    #[BodyParam('login', 'string', 'Email ou numéro de téléphone.', required: true, example: 'jean.client@example.com')]
    #[BodyParam('password', 'string', 'Mot de passe.', required: true, example: 'password')]
    #[Response([
        'data' => [
            'token' => '2|abcExampleClientToken',
            'token_type' => 'Bearer',
            'actor' => 'client',
            'user' => [
                'id' => 1,
                'pressing_id' => 1,
                'agency_id' => 1,
                'code' => 'CLI-00001',
                'fullname' => 'Jean Client',
                'email' => 'jean.client@example.com',
                'phone_number' => '690000001',
                'address' => null,
                'city' => null,
                'loyalty_points' => 0,
                'wallet_balance' => 0,
                'sponsor_code' => 'JEAN01',
                'referred_by_id' => null,
                'last_deposit_at' => null,
                'status' => true,
            ],
        ],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Succès')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Les données sont invalides.'],
        'errors' => [
            ['code' => 'validation', 'field' => 'email', 'detail' => 'Identifiants invalides.'],
        ],
    ], status: 422, description: 'Identifiants invalides')]
    public function clientLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $client = Client::withoutGlobalScopes()
            ->where(function ($query) use ($credentials) {
                $query->where('email', $credentials['login'])
                    ->orWhere('phone_number', $credentials['login']);
            })
            ->first();

        return $this->issueToken($client, $credentials['password'], 'client', function (Client $client) {
            return (new ClientResource($client))->resolve();
        }, $credentials['login'], $request->boolean('remember'), 'login');
    }

    #[Unauthenticated]
    #[Endpoint('Inscription client', 'Crée un compte client et retourne immédiatement un token Bearer.')]
    #[BodyParam('pressing_id', 'integer', 'ID du pressing.', required: true, example: 1)]
    #[BodyParam('agency_id', 'integer', 'Agence de rattachement.', required: false, example: 1)]
    #[BodyParam('fullname', 'string', 'Nom complet.', required: true, example: 'Marie Dupont')]
    #[BodyParam('phone_number', 'string', 'Téléphone unique par pressing.', required: true, example: '690000099')]
    #[BodyParam('email', 'string', 'Email optionnel.', required: false, example: 'marie@example.com')]
    #[BodyParam('password', 'string', 'Mot de passe (min. 8).', required: true, example: 'password')]
    #[BodyParam('sponsor_code', 'string', 'Code parrainage.', required: false, example: 'JEAN01')]
    #[Response([
        'data' => [
            'token' => '3|abcExampleRegisterToken',
            'token_type' => 'Bearer',
            'actor' => 'client',
            'user' => [
                'id' => 2,
                'pressing_id' => 1,
                'agency_id' => 1,
                'code' => 'CLI-00002',
                'fullname' => 'Marie Dupont',
                'email' => 'marie@example.com',
                'phone_number' => '690000099',
                'address' => null,
                'city' => null,
                'loyalty_points' => 0,
                'wallet_balance' => 0,
                'sponsor_code' => 'MARIE1',
                'referred_by_id' => 1,
                'last_deposit_at' => null,
                'status' => true,
            ],
        ],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 201, description: 'Créé')]
    public function clientRegister(Request $request, RegisterClient $register): JsonResponse
    {
        $data = $request->validate([
            'pressing_id' => ['required', 'exists:pressings,id'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'fullname' => ['required', 'string', 'max:191'],
            'phone_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'sponsor_code' => ['nullable', 'string', 'max:12'],
        ]);

        $client = $register->handle($data, withoutGlobalScopes: true);
        $token = $client->createToken('client')->plainTextToken;

        return $this->created([
            'token' => $token,
            'token_type' => 'Bearer',
            'actor' => 'client',
            'user' => (new ClientResource($client))->resolve(),
        ]);
    }

    #[Unauthenticated]
    #[Endpoint('Connexion superadmin', 'Back-office multi-pressing. `actor` vaut `superadmin`.')]
    #[BodyParam('email', 'string', 'Email superadmin.', required: true, example: 'superadmin@spark.local')]
    #[BodyParam('password', 'string', 'Mot de passe.', required: true, example: 'password')]
    #[Response([
        'data' => [
            'token' => '4|abcExampleSuperadminToken',
            'token_type' => 'Bearer',
            'actor' => 'superadmin',
            'user' => [
                'id' => 1,
                'fullname' => 'Super Admin SPARK',
                'email' => 'superadmin@spark.local',
                'type' => 'superadmin',
                'status' => true,
                'roles' => ['superadmin'],
            ],
        ],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Succès')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Les données sont invalides.'],
        'errors' => [
            ['code' => 'validation', 'field' => 'email', 'detail' => 'Identifiants invalides.'],
        ],
    ], status: 422, description: 'Identifiants invalides')]
    public function adminLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::query()->where('email', $credentials['email'])->first();

        return $this->issueToken($admin, $credentials['password'], 'superadmin', function (Admin $admin) {
            return $this->adminPayload($admin);
        }, $credentials['email'], $request->boolean('remember'));
    }

    #[Endpoint('Profil courant', 'Retourne l’acteur authentifié (staff, client ou superadmin) selon le token Bearer.')]
    #[Response([
        'data' => [
            'id' => 1,
            'fullname' => 'Claire Caissier',
            'email' => 'caissier@elegance.local',
            'phone_number' => null,
            'pressing_id' => 1,
            'agency_id' => 1,
            'status' => true,
            'roles' => ['caissier'],
            'permissions' => ['deposits.view', 'deposits.create', 'cash.manage'],
            'agency' => ['id' => 1, 'name' => 'Akwa', 'currency' => 'XAF'],
            'agencies' => [['id' => 1, 'name' => 'Akwa']],
            'pressing' => ['id' => 1, 'name' => 'Élégance Pressing'],
            'can_view_all_agencies' => false,
        ],
        'meta' => [
            'actor' => 'staff',
            'can_view_all_agencies' => false,
            'allowed_agency_ids' => [1],
        ],
        'errors' => null,
    ], status: 200, description: 'Staff')]
    #[Response([
        'data' => null,
        'meta' => ['message' => 'Non authentifié.'],
        'errors' => [
            ['code' => 'unauthenticated', 'detail' => 'Non authentifié.'],
        ],
    ], status: 401, description: 'Non authentifié')]
    public function me(Request $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor instanceof User) {
            $actor->load(['agency', 'pressing', 'roles', 'agencies']);

            return $this->ok((new UserResource($actor))->resolve(), [
                'actor' => 'staff',
                'can_view_all_agencies' => $actor->canViewAllAgencies(),
                'allowed_agency_ids' => $actor->allowedAgencyIds(),
                'contexts' => $this->contexts($actor),
            ]);
        }

        if ($actor instanceof Client) {
            return $this->ok((new ClientResource($actor))->resolve(), ['actor' => 'client']);
        }

        if ($actor instanceof Admin) {
            return $this->ok($this->adminPayload($actor), ['actor' => 'superadmin']);
        }

        return ApiResponse::error('Non authentifié.', 401);
    }

    #[Endpoint('Mettre à jour le profil')]
    #[BodyParam('fullname', 'string', required: false, example: 'Claire Caissier')]
    #[BodyParam('phone_number', 'string', required: false, example: '690000010')]
    #[BodyParam('address', 'string', required: false)]
    #[BodyParam('email', 'string', required: false, example: 'caissier@elegance.local')]
    #[BodyParam('password', 'string', 'Nouveau mot de passe (min. 8).', required: false, example: 'password')]
    #[BodyParam('password_confirmation', 'string', required: false, example: 'password')]
    #[Response([
        'data' => [
            'id' => 1,
            'fullname' => 'Claire Caissier',
            'email' => 'caissier@elegance.local',
            'phone_number' => '690000010',
            'pressing_id' => 1,
            'agency_id' => 1,
            'status' => true,
            'roles' => ['caissier'],
        ],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Profil mis à jour')]
    public function updateProfile(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'fullname' => ['sometimes', 'string', 'max:191'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string'],
            'email' => ['sometimes', 'email'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if ($actor instanceof User && isset($data['fullname'])) {
            $data['name'] = $data['fullname'];
        }

        $actor->update($data);

        if ($actor instanceof User) {
            return $this->ok((new UserResource($actor->fresh(['roles', 'agency', 'pressing'])))->resolve());
        }

        return $this->ok((new ClientResource($actor->fresh()))->resolve());
    }

    #[Unauthenticated]
    #[Endpoint('Canaux de réinitialisation', '« Recevoir le code par » : e-mail et SMS masqués (ex. « SMS •••• 42 »).')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[Response([
        'data' => ['channels' => [
            ['code' => 'email', 'label' => 'E-mail', 'destination' => 'ca•••••••@elegance.local'],
            ['code' => 'sms', 'label' => 'SMS', 'destination' => '•••• 42'],
        ]],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Canaux disponibles')]
    public function resetChannels(Request $request, PasswordResetCodes $codes): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        return $this->ok(['channels' => $codes->channels($request->string('email')->toString())]);
    }

    #[Unauthenticated]
    #[Endpoint('Mot de passe oublié (étape 1/3)', 'Envoie un code à 6 chiffres valable 10 minutes. Anti-abus : 2 demandes par 15 minutes (429 sinon).')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('channel', 'string', 'email ou sms.', required: false, example: 'email')]
    #[Response([
        'data' => ['sent' => true, 'channel' => 'email', 'destination' => 'ca•••••••@elegance.local', 'expires_in' => 600, 'step' => 1, 'steps' => 3],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Code envoyé (toujours 200 pour ne pas divulguer l’existence du compte)')]
    public function forgotPassword(Request $request, PasswordResetCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'channel' => ['nullable', 'in:email,sms'],
        ]);
        $channel = $data['channel'] ?? 'email';

        if ($retry = $codes->throttled($data['email'])) {
            return ApiResponse::error(
                'Contrôle anti-abus : 2 demandes toutes les 15 minutes.',
                429,
                [['code' => 'throttled', 'detail' => "Nouvelle demande possible dans {$retry} secondes."]],
                ['retry_after' => $retry],
            );
        }

        $codes->send($data['email'], $channel);
        $destination = collect($codes->channels($data['email']))->firstWhere('code', $channel)['destination']
            ?? PasswordResetCodes::maskEmail($data['email']);

        return $this->ok([
            'sent' => true,
            'channel' => $channel,
            'destination' => $destination,
            'expires_in' => PasswordResetCodes::CODE_TTL,
            'step' => 1,
            'steps' => 3,
        ]);
    }

    #[Unauthenticated]
    #[Endpoint('Vérifier le code (étape 2/3)', 'Échange le code à 6 chiffres contre un `reset_token` (15 min).')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('code', 'string', required: true, example: '482913')]
    #[Response([
        'data' => ['reset_token' => 'Q2x…', 'expires_in' => 900, 'step' => 2, 'steps' => 3],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Code valide')]
    public function verifyResetCode(Request $request, PasswordResetCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $token = $codes->verify($data['email'], $data['code']);
        if (! $token) {
            throw ValidationException::withMessages(['code' => 'Code invalide ou expiré.']);
        }

        return $this->ok([
            'reset_token' => $token,
            'expires_in' => PasswordResetCodes::TOKEN_TTL,
            'step' => 2,
            'steps' => 3,
        ]);
    }

    #[Unauthenticated]
    #[Endpoint('Réinitialiser le mot de passe (étape 3/3)', 'Accepte le `reset_token` issu du code, ou le `token` d’un lien e-mail.')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('reset_token', 'string', 'Jeton reçu à l’étape 2.', required: false, example: 'Q2x…')]
    #[BodyParam('token', 'string', 'Token reçu par lien e-mail (ancien flux).', required: false, example: 'reset-token')]
    #[BodyParam('password', 'string', required: true, example: 'password')]
    #[BodyParam('password_confirmation', 'string', required: true, example: 'password')]
    #[Response([
        'data' => ['reset' => true],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Mot de passe réinitialisé')]
    public function resetPassword(Request $request, PasswordResetCodes $codes): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'reset_token' => ['required_without:token', 'nullable', 'string'],
            'token' => ['required_without:reset_token', 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($request->filled('reset_token')) {
            $user = User::query()->where('email', $request->email)->first();
            if (! $user || ! $codes->consume($user, $request->reset_token)) {
                throw ValidationException::withMessages(['reset_token' => 'Jeton invalide ou expiré.']);
            }

            $user->forceFill([
                'password' => $request->password,
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));

            return $this->ok(['reset' => true]);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => $request->password,
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return $this->ok(['reset' => true]);
    }

    #[Endpoint('Déconnexion', 'Invalide le token Sanctum courant.')]
    #[Response([
        'data' => ['logged_out' => true],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Déconnecté')]
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->ok(['logged_out' => true]);
    }

    private function issueToken(
        ?object $actor,
        string $password,
        string $type,
        callable $resolver,
        string $identifier = '',
        bool $remember = false,
        string $field = 'email',
    ): JsonResponse {
        $throttleKey = LoginThrottle::key($type, $identifier);
        // Politique « Sécurité » du pressing (09) : échecs avant verrouillage, durée, durée de session.
        $settings = app(SettingsRegistry::class);
        $pressingId = $actor->pressing_id ?? null;
        $maxAttempts = (int) $settings->value($pressingId, 'security', 'lockout.max_attempts', LoginThrottle::MAX_ATTEMPTS);
        $lockMinutes = (int) $settings->value($pressingId, 'security', 'lockout.lock_minutes', LoginThrottle::LOCK_SECONDS / 60);

        if (LoginThrottle::locked($throttleKey, $maxAttempts)) {
            $retry = LoginThrottle::retryAfter($throttleKey);

            return ApiResponse::error(
                "Trop de tentatives. Accès limité pendant {$lockMinutes} minutes.",
                429,
                [['code' => 'locked', 'detail' => "Réessayez dans {$retry} secondes."]],
                ['retry_after' => $retry, 'attempts_remaining' => 0],
            );
        }

        if (! $actor || ! Hash::check($password, $actor->password)) {
            $remaining = LoginThrottle::fail($throttleKey, $maxAttempts, $lockMinutes * 60);

            return ApiResponse::error(
                'Les données sont invalides.',
                422,
                [['code' => 'validation', 'field' => $field, 'detail' => 'Identifiants invalides.']],
                ['attempts_remaining' => $remaining, 'max_attempts' => $maxAttempts],
            );
        }

        if (isset($actor->status) && $actor->status === false) {
            return ApiResponse::error('Compte désactivé.', 403);
        }

        // Authentification renforcée (Profil et sécurité) : code TOTP ou code de secours.
        if ($actor instanceof User && $actor->mfa_enabled_at) {
            $otp = (string) request()?->input('otp', '');
            if ($otp === '') {
                return ApiResponse::error('Code d’authentification requis.', 422,
                    [['code' => 'mfa_required', 'field' => 'otp', 'detail' => 'Saisissez le code de votre application d’authentification.']],
                    ['mfa_required' => true]);
            }
            if (! $this->checkSecondFactor($actor, $otp)) {
                $remaining = LoginThrottle::fail($throttleKey, $maxAttempts, $lockMinutes * 60);

                return ApiResponse::error('Les données sont invalides.', 422,
                    [['code' => 'validation', 'field' => 'otp', 'detail' => 'Code d’authentification invalide.']],
                    ['mfa_required' => true, 'attempts_remaining' => $remaining, 'max_attempts' => $maxAttempts]);
            }
        }

        LoginThrottle::clear($throttleKey);

        $sessionHours = $type === 'staff' && $pressingId ? (int) $settings->value($pressingId, 'security', 'sessions.max_hours', 12) : 12;
        $expiresAt = $remember ? now()->addDays(30) : now()->addHours($sessionHours);
        $newToken = $actor->createToken($type, ['*'], $expiresAt);
        $newToken->accessToken->forceFill([
            'ip_address' => request()?->ip(),
            'user_agent' => Str::limit((string) request()?->userAgent(), 255, ''),
        ])->save();
        if ($actor instanceof User) {
            $actor->forceFill(['last_login_at' => now()])->save();
        }

        return $this->ok([
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'actor' => $type,
            'user' => $resolver($actor),
        ]);
    }

    private function checkSecondFactor(User $user, string $otp): bool
    {
        if ($user->mfa_secret && Totp::verify($user->mfa_secret, $otp)) {
            return true;
        }
        $codes = $user->mfa_recovery_codes ?? [];
        $index = array_search(strtoupper(trim($otp)), $codes, true);
        if ($index === false) {
            return false;
        }
        unset($codes[$index]);
        $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /**
     * Sélecteur « Agence ou contexte » (ex. « Cocody Angré • Manager »).
     *
     * @return list<array{agency_id:int,agency:string,role:?string,label:string,is_home:bool}>
     */
    private function contexts(User $user): array
    {
        $role = $user->getRoleNames()->first();
        $roleLabel = $role ? (self::ROLE_LABELS[$role] ?? ucfirst($role)) : null;

        return Agency::query()
            ->whereIn('id', $user->allowedAgencyIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Agency $agency) => [
                'agency_id' => $agency->id,
                'agency' => $agency->name,
                'role' => $role,
                'label' => $roleLabel ? "{$agency->name} • {$roleLabel}" : $agency->name,
                'is_home' => (int) $agency->id === (int) $user->agency_id,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function adminPayload(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'fullname' => $admin->fullname,
            'email' => $admin->email,
            'type' => 'superadmin',
            'status' => (bool) $admin->status,
            'roles' => ['superadmin'],
        ];
    }
}
