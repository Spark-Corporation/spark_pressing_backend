<?php

namespace Database\Seeders;

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
            ],
            'admin' => $permissions,
            'laveur' => ['deposits.view', 'workshop.transition'],
            'classeur' => ['deposits.view', 'workshop.transition'],
            'livreur' => [
                'deposits.view', 'deposits.pay', 'deliveries.view', 'deliveries.collect',
            ],
        ];

        foreach ($matrix as $role => $perms) {
            Role::findOrCreate($role, 'web')->syncPermissions($perms);
        }
    }
}
