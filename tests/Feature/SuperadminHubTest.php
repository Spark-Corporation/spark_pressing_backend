<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Hub superadmin Figma 13–13B (hors centre licences 13C). */
class SuperadminHubTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_overview_and_enriched_lists(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['name' => 'Élégance']);
        $this->makeStaff($agency, 'caissier', ['fullname' => 'Claire']);

        $admin = Admin::query()->create([
            'fullname' => 'Root',
            'email' => 'root@spark.local',
            'password' => 'password',
            'status' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/superadmin/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.pressings.value', 1)
            ->assertJsonPath('data.kpis.agencies.value', 1)
            ->assertJsonPath('data.shortcuts.0.code', 'pressings');

        $this->getJson('/api/v1/superadmin/pressings')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Élégance')
            ->assertJsonPath('data.0.agencies', 1)
            ->assertJsonPath('data.0.users', 1);

        $this->getJson('/api/v1/superadmin/users?q=Claire')
            ->assertOk()
            ->assertJsonPath('data.0.fullname', 'Claire')
            ->assertJsonPath('data.0.pressing.name', 'Élégance');

        $this->getJson('/api/v1/superadmin/agencies')
            ->assertOk()
            ->assertJsonPath('data.0.pressing.name', 'Élégance');
    }
}
