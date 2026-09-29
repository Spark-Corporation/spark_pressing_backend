<?php

namespace Tests\Concerns;

use App\Models\Agency;
use App\Models\AgencyPrice;
use App\Models\Article;
use App\Models\Client;
use App\Models\Pressing;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

trait CreatesSparkFixtures
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function makePressingWithAgency(array $pressing = [], array $agency = []): array
    {
        $pressingModel = Pressing::factory()->create($pressing);
        $agencyModel = Agency::factory()->create(array_merge([
            'pressing_id' => $pressingModel->id,
        ], $agency));

        $this->seedDefaultServices($pressingModel);

        return [$pressingModel, $agencyModel];
    }

    protected function seedDefaultServices(Pressing $pressing): void
    {
        foreach ([
            ['name' => 'Classique', 'code' => 'classic', 'legacy_type_action' => 0, 'sort_order' => 1, 'default_hours' => 48],
            ['name' => 'Express', 'code' => 'express', 'legacy_type_action' => 1, 'sort_order' => 2, 'default_hours' => 24],
            ['name' => 'Repassage', 'code' => 'repass', 'legacy_type_action' => 2, 'sort_order' => 3, 'default_hours' => 12],
        ] as $row) {
            Service::query()->firstOrCreate(
                ['pressing_id' => $pressing->id, 'code' => $row['code']],
                $row + ['status' => true]
            );
        }
    }

    protected function makeStaff(Agency $agency, string $role = 'caissier', array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'pressing_id' => $agency->pressing_id,
            'agency_id' => $agency->id,
        ], $attrs));

        $user->assignRole($role);
        $user->agencies()->syncWithoutDetaching([$agency->id => ['is_home' => true]]);

        return $user;
    }

    protected function makeClient(Agency $agency, array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'pressing_id' => $agency->pressing_id,
            'agency_id' => $agency->id,
        ], $attrs));
    }

    protected function makeArticle(Pressing $pressing, array $attrs = []): Article
    {
        return Article::factory()->create(array_merge([
            'pressing_id' => $pressing->id,
        ], $attrs));
    }

    protected function makeAgencyPrice(Agency $agency, Article $article, Service $service, int $amount, array $attrs = []): AgencyPrice
    {
        return AgencyPrice::query()->create(array_merge([
            'pressing_id' => $agency->pressing_id,
            'agency_id' => $agency->id,
            'article_id' => $article->id,
            'service_id' => $service->id,
            'pricing_type' => 'piece',
            'amount_minor' => $amount,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => null,
        ], $attrs));
    }
}
