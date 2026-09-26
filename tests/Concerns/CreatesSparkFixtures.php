<?php

namespace Tests\Concerns;

use App\Models\Agency;
use App\Models\Article;
use App\Models\Client;
use App\Models\Pressing;
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

        return [$pressingModel, $agencyModel];
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
}
