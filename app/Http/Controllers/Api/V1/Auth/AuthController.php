<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Client\RegisterClient;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\UserResource;
use App\Models\Admin;
use App\Models\Client;
use App\Models\User;
use App\Support\ApiResponse;
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
    #[Unauthenticated]
    #[Endpoint('Connexion staff', 'Retourne un token Sanctum Bearer et le profil staff (rôles, agence, pressing).')]
    #[BodyParam('email', 'string', 'Email du compte staff.', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('password', 'string', 'Mot de passe.', required: true, example: 'password')]
    #[Response([
        'data' => [
            'token' => '1|abcExampleSanctumToken',
            'token_type' => 'Bearer',
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        return $this->issueToken($user, $credentials['password'], 'staff', function (User $user) {
            $user->load(['agency', 'pressing', 'roles', 'agencies']);

            return (new UserResource($user))->resolve();
        });
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
                'code' => 'C000001',
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
        });
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
                'code' => 'C000002',
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
            return [
                'id' => $admin->id,
                'fullname' => $admin->fullname,
                'email' => $admin->email,
                'type' => 'superadmin',
            ];
        });
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
            ]);
        }

        if ($actor instanceof Client) {
            return $this->ok((new ClientResource($actor))->resolve(), ['actor' => 'client']);
        }

        if ($actor instanceof Admin) {
            return $this->ok([
                'id' => $actor->id,
                'fullname' => $actor->fullname,
                'email' => $actor->email,
            ], ['actor' => 'superadmin']);
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
    #[Endpoint('Mot de passe oublié')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[Response([
        'data' => ['sent' => true],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Lien envoyé (toujours 200 pour ne pas divulguer l’existence du compte)')]
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker()->sendResetLink($request->only('email'));

        return $this->ok(['sent' => true]);
    }

    #[Unauthenticated]
    #[Endpoint('Réinitialiser le mot de passe')]
    #[BodyParam('email', 'string', required: true, example: 'caissier@elegance.local')]
    #[BodyParam('token', 'string', 'Token reçu par email.', required: true, example: 'reset-token')]
    #[BodyParam('password', 'string', required: true, example: 'password')]
    #[BodyParam('password_confirmation', 'string', required: true, example: 'password')]
    #[Response([
        'data' => ['reset' => true],
        'meta' => new \stdClass,
        'errors' => null,
    ], status: 200, description: 'Mot de passe réinitialisé')]
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

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

    private function issueToken(?object $actor, string $password, string $type, callable $resolver): JsonResponse
    {
        if (! $actor || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        if (isset($actor->status) && $actor->status === false) {
            return ApiResponse::error('Compte désactivé.', 403);
        }

        $token = $actor->createToken($type)->plainTextToken;

        return $this->ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'actor' => $type,
            'user' => $resolver($actor),
        ]);
    }
}
