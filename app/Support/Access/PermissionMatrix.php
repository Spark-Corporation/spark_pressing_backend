<?php

namespace App\Support\Access;

use App\Models\RoleProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/** Catalogue des droits fins (config/permission_matrix.php) et matrice des rôles. */
class PermissionMatrix
{
    /** @return array<string, array{domain:string,label:string,column:string,sensitive:bool,grants:list<string>}> */
    public static function actions(): array
    {
        $out = [];
        foreach (config('permission_matrix.domains') as $domain => $def) {
            foreach ($def['actions'] as $name => $action) {
                $out[$name] = ['domain' => $domain, 'label' => $action['label'], 'column' => $action['column'], 'sensitive' => (bool) ($action['sensitive'] ?? false), 'grants' => $action['grants']];
            }
        }

        return $out;
    }

    /** Résout une liste du type `['*', '!audit.export']` en droits fins. */
    public static function resolve(array $spec): array
    {
        $all = array_keys(self::actions());
        $set = in_array('*', $spec, true) ? $all : array_values(array_intersect($all, $spec));

        return array_values(array_diff($set, array_map(fn ($s) => ltrim($s, '!'), array_filter($spec, fn ($s) => str_starts_with($s, '!')))));
    }

    /** Permissions techniques (middleware des routes) déduites des droits fins. */
    public static function grants(array $actions): array
    {
        $catalog = self::actions();

        return collect($actions)->flatMap(fn ($a) => $catalog[$a]['grants'] ?? [])->unique()->values()->all();
    }

    /** Droits fins détenus par un rôle. */
    public static function roleActions(Role $role): array
    {
        return array_values(array_intersect(array_keys(self::actions()), $role->permissions->pluck('name')->all()));
    }

    /** Droits fins que l’utilisateur peut déléguer (« Hors de vos droits · non délégable »). */
    public static function delegable(User $user): array
    {
        $mine = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect(array_keys(self::actions()), $mine));
    }

    /** Domaines × actions pour le formulaire « Créer un rôle » (cases cochées/verrouillées). */
    public static function catalog(array $selected = [], ?array $delegable = null): array
    {
        return collect(config('permission_matrix.domains'))->map(function ($def, $domain) use ($selected, $delegable) {
            $actions = collect($def['actions'])->map(fn ($a, $name) => [
                'permission' => $name,
                'label' => $a['label'],
                'column' => $a['column'],
                'sensitive' => (bool) ($a['sensitive'] ?? false),
                'selected' => in_array($name, $selected, true),
                'delegable' => $delegable === null || in_array($name, $delegable, true),
            ])->values();

            return [
                'domain' => $domain,
                'label' => $def['label'],
                'detail' => $def['detail'],
                'selected' => $actions->where('selected', true)->count(),
                'total' => $actions->count(),
                'actions' => $actions->all(),
            ];
        })->values()->all();
    }

    /** Synthèse « 17 permissions · 9 domaines · 2 droits sensibles ». */
    public static function summary(array $selected): array
    {
        $catalog = self::actions();
        $picked = collect($selected)->filter(fn ($a) => isset($catalog[$a]));

        return [
            'permissions' => $picked->count(),
            'total' => count($catalog),
            'domains' => $picked->map(fn ($a) => $catalog[$a]['domain'])->unique()->count(),
            'sensitive' => $picked->filter(fn ($a) => $catalog[$a]['sensitive'])->values()->all(),
            'by_domain' => collect(config('permission_matrix.domains'))->map(fn ($d, $code) => [
                'domain' => $code, 'label' => $d['label'], 'count' => $picked->filter(fn ($a) => $catalog[$a]['domain'] === $code)->count(),
            ])->values()->all(),
        ];
    }

    /** Cellule de matrice d’un rôle pour un domaine : all | partial | none. */
    public static function cell(array $roleActions, string $domain): string
    {
        $domainActions = array_keys(config("permission_matrix.domains.{$domain}.actions"));
        $held = count(array_intersect($domainActions, $roleActions));

        return match (true) {
            $held === 0 => 'none',
            $held === count($domainActions) => 'all',
            default => 'partial',
        };
    }

    /** Rôles visibles par un pressing : rôles système + rôles personnalisés du pressing. */
    public static function rolesFor(int $pressingId): Collection
    {
        $profiles = RoleProfile::query()->where('pressing_id', $pressingId)->get()->keyBy('role_id');
        $system = array_keys(config('permission_matrix.system_roles'));

        return Role::query()->where('guard_name', 'web')->with('permissions:id,name')->get()
            ->filter(fn (Role $r) => in_array($r->name, $system, true) || $profiles->has($r->id))
            ->map(function (Role $r) use ($profiles, $system) {
                $profile = $profiles->get($r->id);
                $meta = config("permission_matrix.system_roles.{$r->name}");

                return (object) [
                    'role' => $r,
                    'system' => in_array($r->name, $system, true),
                    'label' => $profile?->label ?? $meta['label'] ?? $r->name,
                    'description' => $profile?->description ?? $meta['description'] ?? null,
                    'scope' => $profile?->scope ?? $meta['scope'] ?? 'assigned',
                    'data_level' => $profile?->data_level ?? $meta['data_level'] ?? 'agency',
                    'inheritance' => $meta['inheritance'] ?? 'Limité par vos droits',
                    'status' => $profile?->status ?? 'active',
                    'profile' => $profile,
                ];
            })
            ->sortBy(fn ($r) => [$r->system ? 0 : 1, array_search($r->role->name, $system, true)])
            ->values();
    }
}
