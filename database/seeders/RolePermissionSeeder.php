<?php

namespace Database\Seeders;

use App\Support\Access\PermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'deposits.view',
            'deposits.create',
            'deposits.pay',
            'deposits.retrieve',
            'clients.manage',
            'articles.manage',
            'reports.view',
            'reports.consolidated',
            'cash.manage',
            'workshop.transition',
            'agencies.manage',
            'settings.manage',
            'audit.view',
            'roles.manage',
            'deliveries.view',
            'deliveries.manage',
            'deliveries.collect',
            'prices.manage',
            'fx.manage',
            'deposits.override',
            'cash.approve',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $matrix = [
            'caissier' => [
                'deposits.view', 'deposits.create', 'deposits.pay', 'deposits.retrieve',
                'clients.manage', 'reports.view', 'cash.manage', 'deliveries.view',
            ],
            'manager' => [
                'deposits.view', 'deposits.create', 'deposits.pay', 'deposits.retrieve',
                'clients.manage', 'articles.manage', 'reports.view', 'reports.consolidated',
                'cash.manage', 'settings.manage', 'roles.manage',
                'deliveries.view', 'deliveries.manage', 'prices.manage', 'fx.manage',
                'deposits.override', 'cash.approve',
            ],
            'admin' => $permissions,
            'laveur' => ['deposits.view', 'workshop.transition'],
            'classeur' => ['deposits.view', 'workshop.transition'],
            'livreur' => [
                'deposits.view', 'deposits.pay', 'deliveries.view', 'deliveries.collect',
            ],
        ];

        // Droits fins de la matrice « Rôles et permissions » (Figma 11).
        $fine = array_keys(PermissionMatrix::actions());
        foreach ($fine as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $grants = config('permission_matrix.system_grants');

        foreach ($matrix as $role => $perms) {
            $actions = $role === 'admin' ? $fine : PermissionMatrix::resolve($grants[$role] ?? []);
            Role::findOrCreate($role, 'web')->syncPermissions(array_values(array_unique([...$perms, ...$actions])));
        }
    }
}
