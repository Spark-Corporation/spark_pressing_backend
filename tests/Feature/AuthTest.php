<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_staff_can_login_and_fetch_me(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'caissier', [
            'email' => 'caisse@test.local',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/v1/auth/staff/login', [
            'email' => 'caisse@test.local',
            'password' => 'password',
        ])->assertOk();

        $login->assertJsonPath('data.actor', 'staff');
        $this->assertNotEmpty($login->json('data.token'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('meta.actor', 'staff')
            ->assertJsonPath('data.email', 'caisse@test.local');
    }

    public function test_invalid_login_returns_422(): void
    {
        $this->postJson('/api/v1/auth/staff/login', [
            'email' => 'nobody@test.local',
            'password' => 'wrong',
        ])->assertStatus(422)
            ->assertJsonPath('data', null);
    }

    public function test_superadmin_can_login(): void
    {
        Admin::factory()->create([
            'email' => 'root@spark.local',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/superadmin/login', [
            'email' => 'root@spark.local',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.actor', 'superadmin');
    }

    public function test_unauthenticated_api_returns_401(): void
    {
        $this->getJson('/api/v1/deposits')->assertStatus(401);
    }
}
