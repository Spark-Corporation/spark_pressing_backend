<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Actions\Staff\PersistStaffUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyResource;
use App\Http\Resources\UserResource;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Group;

#[Group('Organisation')]
class PressingAgencyController extends Controller
{
    public function agencies(Request $request): JsonResponse
    {
        $items = Agency::query()
            ->where('pressing_id', $request->user()->pressing_id)
            ->latest()
            ->paginate(50);

        return $this->page($items, AgencyResource::collection($items)->resolve());
    }

    public function storeAgency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'code_prefix' => ['nullable', 'string', 'max:8'],
            'code_suffix' => ['nullable', 'string', 'max:8'],
        ]);

        $agency = Agency::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'status' => true,
        ]);

        return $this->created((new AgencyResource($agency))->resolve());
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['roles:id,name', 'agency:id,name'])
            ->where('pressing_id', $request->user()->pressing_id)
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->agency_id, fn ($q, $id) => $q->where('agency_id', $id))
            ->latest()
            ->paginate(50);

        return $this->page($users, UserResource::collection($users)->resolve());
    }

    public function storeUser(Request $request, PersistStaffUser $persist): JsonResponse
    {
        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'agency_id' => ['required', 'exists:agencies,id'],
            'role' => ['required', Rule::in(['admin', 'manager', 'caissier', 'laveur', 'classeur'])],
        ]);

        $user = $persist->create($data + [
            'pressing_id' => $request->user()->pressing_id,
        ]);

        return $this->created((new UserResource($user))->resolve());
    }

    public function updateUser(Request $request, User $user, PersistStaffUser $persist): JsonResponse
    {
        abort_unless((int) $user->pressing_id === (int) $request->user()->pressing_id, 403);

        $data = $request->validate([
            'fullname' => ['sometimes', 'string', 'max:191'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'agency_id' => ['sometimes', 'exists:agencies,id'],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'caissier', 'laveur', 'classeur'])],
            'status' => ['sometimes', 'boolean'],
        ]);

        $user = $persist->update($user, $data + ['pressing_id' => $user->pressing_id]);

        return $this->ok((new UserResource($user))->resolve());
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        abort_unless((int) $user->pressing_id === (int) $request->user()->pressing_id, 403);
        abort_if((int) $user->id === (int) $request->user()->id, 422, 'Vous ne pouvez pas supprimer votre propre compte.');

        $user->delete();

        return $this->ok(['deleted' => true]);
    }
}
