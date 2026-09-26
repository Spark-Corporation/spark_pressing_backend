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
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Authentification')]
class AuthController extends Controller
{
    #[Unauthenticated]
    #[Endpoint('Connexion staff')]
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
    #[Endpoint('Connexion client')]
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
    #[Endpoint('Inscription client')]
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
    #[Endpoint('Connexion superadmin')]
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
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker()->sendResetLink($request->only('email'));

        return $this->ok(['sent' => true]);
    }

    #[Unauthenticated]
    #[Endpoint('Réinitialiser le mot de passe')]
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
