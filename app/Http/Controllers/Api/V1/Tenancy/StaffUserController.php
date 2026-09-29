<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Actions\Staff\PersistStaffUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Group;

#[Group('Superadmin')]
class StaffUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['roles:id,name', 'agency:id,name'])
            ->when($request->pressing_id, fn ($q, $id) => $q->where('pressing_id', $id))
            ->when($request->agency_id, fn ($q, $id) => $q->where('agency_id', $id))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->latest()
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($users, UserResource::collection($users)->resolve());
    }

    public function store(Request $request, PersistStaffUser $persist): JsonResponse
    {
        $data = $request->validate($this->rules());

        $user = $persist->create($data);

        return $this->created((new UserResource($user))->resolve());
    }

    public function update(Request $request, User $user, PersistStaffUser $persist): JsonResponse
    {
        $data = $request->validate($this->rules(true, $user->id));
        $data['pressing_id'] = $data['pressing_id'] ?? $user->pressing_id;

        $user = $persist->update($user, $data);

        return $this->ok((new UserResource($user))->resolve());
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return $this->ok(['deleted' => true]);
    }

    private function rules(bool $update = false, ?int $userId = null): array
    {
        return [
            'fullname' => [$update ? 'sometimes' : 'required', 'string', 'max:191'],
            'email' => [$update ? 'sometimes' : 'required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$update ? 'nullable' : 'required', 'string', 'min:8'],
            'phone_number' => ['nullable', 'string'],
            'pressing_id' => [$update ? 'sometimes' : 'required', 'exists:pressings,id'],
            'agency_id' => [$update ? 'sometimes' : 'required', 'exists:agencies,id'],
            'role' => [$update ? 'sometimes' : 'required', Rule::in(['admin', 'manager', 'caissier', 'laveur', 'classeur', 'livreur'])],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
