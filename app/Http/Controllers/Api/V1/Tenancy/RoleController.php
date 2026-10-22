<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RoleProfile;
use App\Models\User;
use App\Support\Access\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Group('Organisation')]
class RoleController extends Controller
{
    /** Matrice « Rôles et permissions » (Figma 11) : colonnes, domaines, cellules all|partial|none. */
    public function index(Request $request): JsonResponse
    {
        // Superadmin : liste plate historique (pas de pressing).
        if (! ($request->user()->pressing_id ?? null)) {
            return $this->ok(
                Role::query()->where('guard_name', 'web')->with('permissions:id,name')->orderBy('name')->get(['id', 'name'])
            );
        }

        $pressingId = (int) $request->user()->pressing_id;
        $roles = PermissionMatrix::rolesFor($pressingId);
        $userCounts = User::query()->where('pressing_id', $pressingId)->with('roles:id,name')->get()
            ->flatMap(fn (User $u) => $u->roles->pluck('name'))->countBy();

        return $this->ok([
            'columns' => collect(config('permission_matrix.columns'))->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
            'domains' => collect(config('permission_matrix.domains'))->map(fn ($d, $code) => [
                'code' => $code, 'label' => $d['label'], 'detail' => $d['detail'],
            ])->values()->all(),
            'roles' => $roles->map(function ($r) use ($userCounts) {
                $actions = PermissionMatrix::roleActions($r->role);

                return [
                    'id' => $r->role->id,
                    'name' => $r->role->name,
                    'label' => $r->label,
                    'description' => $r->description,
                    'system' => $r->system,
                    'scope' => $r->scope,
                    'data_level' => $r->data_level,
                    'data_level_label' => config("permission_matrix.data_levels.{$r->data_level}"),
                    'inheritance' => $r->inheritance,
                    'status' => $r->status,
                    'status_label' => $r->status === 'active' ? 'Actif' : 'Inactif',
                    'users_count' => (int) ($userCounts[$r->role->name] ?? 0),
                    'summary' => PermissionMatrix::summary($actions),
                    'cells' => collect(config('permission_matrix.domains'))->mapWithKeys(
                        fn ($_, $domain) => [$domain => PermissionMatrix::cell($actions, $domain)]
                    )->all(),
                    'editable' => ! $r->system,
                ];
            })->values()->all(),
        ]);
    }

    /** Formulaire « Créer un rôle » : catalogue coché / verrouillé selon les droits du demandeur. */
    public function catalog(Request $request): JsonResponse
    {
        $selected = $request->validate(['permissions' => ['nullable', 'array'], 'permissions.*' => ['string']])['permissions'] ?? [];

        return $this->ok([
            'columns' => config('permission_matrix.columns'),
            'data_levels' => collect(config('permission_matrix.data_levels'))->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values()->all(),
            'catalog' => PermissionMatrix::catalog($selected, PermissionMatrix::delegable($request->user())),
            'summary' => PermissionMatrix::summary($selected),
        ]);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $meta = $this->resolve($request, $role);
        $actions = PermissionMatrix::roleActions($role->load('permissions'));

        return $this->ok([
            'id' => $role->id,
            'name' => $role->name,
            'label' => $meta->label,
            'description' => $meta->description,
            'system' => $meta->system,
            'scope' => $meta->scope,
            'data_level' => $meta->data_level,
            'agency_ids' => $meta->profile?->agency_ids ?? [],
            'status' => $meta->status,
            'permissions' => $actions,
            'summary' => PermissionMatrix::summary($actions),
            'catalog' => PermissionMatrix::catalog($actions, PermissionMatrix::delegable($request->user())),
            'editable' => ! $meta->system,
        ]);
    }

    /** « Créer un rôle » personnalisé (non système). */
    public function store(Request $request): JsonResponse
    {
        $pressingId = (int) $request->user()->pressing_id;
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:250'],
            'scope' => ['required', Rule::in(['assigned', 'all'])],
            'data_level' => ['required', Rule::in(array_keys(config('permission_matrix.data_levels')))],
            'agency_ids' => ['nullable', 'array'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')->where('pressing_id', $pressingId)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionMatrix::actions()))],
            'confirm_sensitive' => ['nullable', 'boolean'],
        ]);
        $this->assertDelegable($request->user(), $data['permissions']);
        $this->assertSensitive($data);

        $name = $this->uniqueName($pressingId, $data['label']);
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions($this->expand($data['permissions']));
        RoleProfile::query()->create([
            'role_id' => $role->id,
            'pressing_id' => $pressingId,
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'],
            'data_level' => $data['data_level'],
            'agency_ids' => $data['agency_ids'] ?? null,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record('role.created', null, ['role' => $name, 'label' => $data['label'], 'permissions' => $data['permissions']]);

        return $this->created($this->show($request, $role->fresh('permissions'))->getData(true)['data']);
    }

    /**
     * Mise à jour Figma (libellé / droits fins) ou sync plat historique si seul `permissions` technique est envoyé.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $figma = $request->exists('label') || $request->exists('data_level') || $request->exists('scope')
            || $request->exists('confirm_sensitive') || $request->exists('status') || $request->exists('description');

        if (! $figma) {
            return $this->sync($request, $role);
        }

        $meta = $this->resolve($request, $role);
        abort_if($meta->system, 422, 'Les rôles système ne sont pas modifiables.');

        $pressingId = (int) $request->user()->pressing_id;
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:250'],
            'scope' => ['sometimes', Rule::in(['assigned', 'all'])],
            'data_level' => ['sometimes', Rule::in(array_keys(config('permission_matrix.data_levels')))],
            'agency_ids' => ['nullable', 'array'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')->where('pressing_id', $pressingId)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionMatrix::actions()))],
            'confirm_sensitive' => ['nullable', 'boolean'],
        ]);
        if (isset($data['permissions'])) {
            $this->assertDelegable($request->user(), $data['permissions']);
            $this->assertSensitive($data);
            $role->syncPermissions($this->expand($data['permissions']));
        }
        $meta->profile->update(collect($data)->only(['label', 'description', 'scope', 'data_level', 'agency_ids', 'status'])->all());
        AuditLog::record('role.updated', null, ['role' => $role->name, 'label' => $meta->profile->fresh()->label]);

        return $this->ok($this->show($request, $role->fresh('permissions'))->getData(true)['data']);
    }

    /** Liste plate des permissions techniques (compatibilité). */
    public function permissions(): JsonResponse
    {
        return $this->ok(
            Permission::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])
        );
    }

    /** Sync plat de permissions techniques (compatibilité / superadmin). */
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

    private function resolve(Request $request, Role $role): object
    {
        abort_unless($role->guard_name === 'web', 404);
        $meta = PermissionMatrix::rolesFor((int) $request->user()->pressing_id)->first(fn ($r) => $r->role->id === $role->id);
        abort_unless($meta, 404);

        return $meta;
    }

    private function expand(array $actions): array
    {
        return array_values(array_unique([...$actions, ...PermissionMatrix::grants($actions)]));
    }

    private function assertDelegable(User $user, array $actions): void
    {
        $missing = array_diff($actions, PermissionMatrix::delegable($user));
        if ($missing) {
            throw ValidationException::withMessages([
                'permissions' => 'Certaines permissions sont hors de vos droits · non délégables.',
            ]);
        }
    }

    private function assertSensitive(array $data): void
    {
        $sensitive = collect($data['permissions'])->filter(fn ($a) => PermissionMatrix::actions()[$a]['sensitive'] ?? false);
        if ($sensitive->isNotEmpty() && empty($data['confirm_sensitive'])) {
            throw ValidationException::withMessages([
                'confirm_sensitive' => 'Confirmez les droits sensibles : '.$sensitive->implode(', '),
            ]);
        }
    }

    private function uniqueName(int $pressingId, string $label): string
    {
        $base = 'custom_'.$pressingId.'_'.Str::slug($label, '_');
        $name = $base ?: 'custom_'.$pressingId.'_role';
        $i = 1;
        while (Role::query()->where('name', $name)->exists()) {
            $name = $base.'_'.$i++;
        }

        return $name;
    }
}
