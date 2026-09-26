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
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $matrix = [
            'caissier' => [
                'deposits.view', 'deposits.create', 'deposits.pay', 'deposits.retrieve',
                'clients.manage', 'reports.view', 'cash.manage',
            ],
            'manager' => [
                'deposits.view', 'deposits.create', 'deposits.pay', 'deposits.retrieve',
                'clients.manage', 'articles.manage', 'reports.view', 'reports.consolidated',
                'cash.manage', 'settings.manage', 'roles.manage',
            ],
            'admin' => $permissions,
            'laveur' => ['deposits.view', 'workshop.transition'],
            'classeur' => ['deposits.view', 'workshop.transition'],
        ];

        foreach ($matrix as $role => $perms) {
            Role::findOrCreate($role, 'web')->syncPermissions($perms);
        }
    }
}
