<?php

namespace Tests\Feature;

use App\Models\UserInvitation;
use App\Support\Access\PermissionMatrix;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 11 — Équipe, profils & permissions ». */
class TeamScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_team_overview_users_invite_and_role_matrix(): void
    {
        Mail::fake();
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin', ['fullname' => 'Aïcha Koné', 'last_login_at' => now()]);
        Sanctum::actingAs($admin);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $this->withHeaders($headers)->getJson('/api/v1/team/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_users.value', 1)
            ->assertJsonPath('data.tabs.0.code', 'users')
            ->assertJsonStructure(['data' => ['mfa', 'filters' => ['roles'], 'recent_activity']]);

        $this->withHeaders($headers)->getJson('/api/v1/team/users')
            ->assertOk()
            ->assertJsonPath('data.0.fullname', 'Aïcha Koné')
            ->assertJsonPath('data.0.role_label', 'Admin');

        $invite = $this->withHeaders($headers)->postJson('/api/v1/team/invitations', [
            'email' => 'nouveau@elegance.local',
            'fullname' => 'Nouveau Caissier',
            'role' => 'caissier',
            'agency_ids' => [$agency->id],
            'require_mfa' => true,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'nouveau@elegance.local')
            ->assertJsonPath('data.status', 'pending');

        $token = UserInvitation::query()->find($invite->json('data.id'))->token;
        $this->postJson("/api/v1/invitations/{$token}/accept", [
            'password' => 'Password1234',
            'password_confirmation' => 'Password1234',
        ])->assertCreated()->assertJsonPath('data.mfa_required', true);

        $this->withHeaders($headers)->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'admin')
            ->assertJsonPath('data.roles.0.cells.clients', 'all')
            ->assertJsonStructure(['data' => ['columns', 'domains', 'roles' => [['summary', 'cells']]]]);

        $this->withHeaders($headers)->getJson('/api/v1/roles/catalog')
            ->assertOk()
            ->assertJsonPath('data.catalog.0.domain', 'clients');

        $created = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'label' => 'Superviseur caisse',
            'description' => 'Encadre la caisse d’une agence',
            'scope' => 'assigned',
            'data_level' => 'agency',
            'permissions' => ['cash.view', 'cash.open', 'reports.view'],
        ])->assertCreated()
            ->assertJsonPath('data.label', 'Superviseur caisse')
            ->assertJsonPath('data.system', false);

        $this->assertNotEmpty(PermissionMatrix::roleActions(
            \Spatie\Permission\Models\Role::find($created->json('data.id'))->load('permissions')
        ));
    }

    public function test_mfa_setup_confirm_and_disable(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'admin');
        Sanctum::actingAs($user);
        $headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];

        $setup = $this->withHeaders($headers)->postJson('/api/v1/team/mfa/setup')->assertOk();
        $secret = $setup->json('data.secret');
        $this->withHeaders($headers)->postJson('/api/v1/team/mfa/confirm', ['code' => Totp::code($secret)])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(8, 'data.recovery_codes');

        $this->withHeaders($headers)->postJson('/api/v1/team/mfa/disable', ['password' => 'password', 'code' => Totp::code($secret)])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }
}
