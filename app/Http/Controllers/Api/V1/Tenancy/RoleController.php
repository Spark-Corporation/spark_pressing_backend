<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Group('Organisation')]
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok($roles);
    }

    public function permissions(): JsonResponse
    {
        return $this->ok(
            Permission::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])
        );
    }

    public function sync(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions']);

        return $this->ok($role->load('permissions:id,name'));
    }
}
