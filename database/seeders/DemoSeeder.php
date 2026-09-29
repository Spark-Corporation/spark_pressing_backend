<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Article;
use App\Models\CashMovementCategory;
use App\Models\Client;
use App\Models\License;
use App\Models\Pressing;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Admin::query()->firstOrCreate(
            ['email' => 'superadmin@spark.local'],
            ['fullname' => 'Super Admin SPARK', 'password' => 'password', 'status' => true]
        );

        $pressing = Pressing::query()->firstOrCreate(
            ['name' => 'Élégance Pressing'],
            [
                'details' => 'Enseigne de démonstration',
                'status' => true,
                'pricing_mode' => 'piece',
            ]
        );

        $akwa = Agency::query()->firstOrCreate(
            ['pressing_id' => $pressing->id, 'name' => 'Akwa'],
            [
                'address' => 'Douala, Akwa',
                'contact' => '+237600000001',
                'country_code' => 'CM',
                'currency' => 'XAF',
                'code_prefix' => 'AK',
                'code_suffix' => 'EL',
                'status' => true,
            ]
        );

        $bonaberi = Agency::query()->firstOrCreate(
            ['pressing_id' => $pressing->id, 'name' => 'Bonabéri'],
            [
                'address' => 'Douala, Bonabéri',
                'country_code' => 'CM',
                'currency' => 'XAF',
                'code_prefix' => 'BB',
                'code_suffix' => 'EL',
                'status' => true,
            ]
        );

        $staff = [
            ['email' => 'caissier@elegance.local', 'fullname' => 'Claire Caissier', 'role' => 'caissier', 'agency' => $akwa],
            ['email' => 'manager@elegance.local', 'fullname' => 'Marc Manager', 'role' => 'manager', 'agency' => $akwa],
            ['email' => 'admin@elegance.local', 'fullname' => 'Amina Admin', 'role' => 'admin', 'agency' => $akwa],
            ['email' => 'laveur@elegance.local', 'fullname' => 'Léo Laveur', 'role' => 'laveur', 'agency' => $akwa],
            ['email' => 'classeur@elegance.local', 'fullname' => 'Carla Classeur', 'role' => 'classeur', 'agency' => $akwa],
            ['email' => 'livreur@elegance.local', 'fullname' => 'Paul Livreur', 'role' => 'livreur', 'agency' => $akwa],
            ['email' => 'caissier.bb@elegance.local', 'fullname' => 'Boris Bonabéri', 'role' => 'caissier', 'agency' => $bonaberi],
        ];

        foreach ($staff as $row) {
            $user = User::query()->firstOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['fullname'],
                    'fullname' => $row['fullname'],
                    'password' => 'password',
                    'pressing_id' => $pressing->id,
                    'agency_id' => $row['agency']->id,
                    'status' => true,
                ]
            );
            $user->syncRoles([$row['role']]);
            $user->agencies()->syncWithoutDetaching([$row['agency']->id => ['is_home' => true]]);
        }

        Client::query()->firstOrCreate(
            ['pressing_id' => $pressing->id, 'phone_number' => '690000001'],
            [
                'agency_id' => $akwa->id,
                'fullname' => 'Jean Client',
                'email' => 'jean.client@example.com',
                'password' => 'password',
                'code' => 'C000001',
                'sponsor_code' => 'JEAN01',
                'status' => true,
            ]
        );

        License::query()->firstOrCreate(
            ['pressing_id' => $pressing->id, 'plan' => 'pro'],
            [
                'seats' => 10,
                'code' => 'DEMO-LICENSE',
                'is_activated' => true,
                'activated_at' => now(),
                'expires_at' => now()->addYear(),
                'status' => true,
            ]
        );

        foreach ([
            ['name' => 'Chemise', 'classic_price' => 1000, 'express_price' => 1500, 'repass_price' => 700],
            ['name' => 'Pantalon', 'classic_price' => 1500, 'express_price' => 2000, 'repass_price' => 800],
            ['name' => 'Costume', 'classic_price' => 4000, 'express_price' => 5500, 'repass_price' => 2500],
        ] as $article) {
            Article::query()->firstOrCreate(
                ['pressing_id' => $pressing->id, 'name' => $article['name']],
                $article + ['status' => true]
            );
        }

        foreach ([
            ['name' => 'Classique', 'code' => 'classic', 'legacy_type_action' => 0, 'sort_order' => 1, 'default_hours' => 48],
            ['name' => 'Express', 'code' => 'express', 'legacy_type_action' => 1, 'sort_order' => 2, 'default_hours' => 24],
            ['name' => 'Repassage', 'code' => 'repass', 'legacy_type_action' => 2, 'sort_order' => 3, 'default_hours' => 12],
        ] as $service) {
            \App\Models\Service::query()->firstOrCreate(
                ['pressing_id' => $pressing->id, 'code' => $service['code']],
                $service + ['status' => true]
            );
        }

        foreach ([
            ['name' => 'Achat fournitures', 'direction' => 'out'],
            ['name' => 'Retrait espèces', 'direction' => 'out'],
            ['name' => 'Fonds de caisse', 'direction' => 'in'],
        ] as $category) {
            CashMovementCategory::query()->firstOrCreate(
                ['pressing_id' => $pressing->id, 'name' => $category['name']],
                $category + ['status' => true]
            );
        }

        $this->command?->info('Comptes démo (mot de passe: password)');
        $this->command?->info('Superadmin: '.$admin->email);
        $this->command?->info('Caissier Akwa: caissier@elegance.local');
        $this->command?->info('Client: jean.client@example.com / 690000001');
    }
}
