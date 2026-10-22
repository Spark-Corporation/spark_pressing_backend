<?php

namespace Tests\Feature;

use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écran Figma « 11 — Profil et sécurité staff ». */
class StaffProfileTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_profile_password_sessions_and_mfa_login(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $this->makeStaff($agency, 'manager', ['email' => 'nadine@spark.ci', 'fullname' => 'Nadine Essomba']);

        $login = fn (array $extra = []) => $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Safari'])
            ->postJson('/api/v1/auth/staff/login', ['login' => 'nadine@spark.ci', 'password' => 'password'] + $extra);
        $other = $login()->assertOk()->json('data.token');
        $token = $login()->assertOk()->json('data.token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($auth)->getJson('/api/v1/auth/staff/profile')
            ->assertOk()
            ->assertJsonPath('data.identity.first_name', 'Nadine')
            ->assertJsonPath('data.role.code', 'manager')
            ->assertJsonPath('data.role.managed_by_admin', true)
            ->assertJsonPath('data.preferences.daily_summary', true)
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.sessions.0.device', 'iPhone · Safari');

        $this->withHeaders($auth)->putJson('/api/v1/auth/staff/profile', ['job_title' => 'Responsable d’agence', 'preferences' => ['whatsapp_notifications' => false]])
            ->assertOk()
            ->assertJsonPath('data.identity.job_title', 'Responsable d’agence')
            ->assertJsonPath('data.preferences.whatsapp_notifications', false)
            ->assertJsonPath('data.preferences.daily_summary', true);

        $this->withHeaders($auth)->putJson('/api/v1/auth/staff/password', ['current_password' => 'password', 'password' => 'faible', 'password_confirmation' => 'faible'])
            ->assertStatus(422);
        $this->withHeaders($auth)->putJson('/api/v1/auth/staff/password', ['current_password' => 'password', 'password' => 'NouveauMotDePasse1', 'password_confirmation' => 'NouveauMotDePasse1'])
            ->assertOk();

        $this->withHeaders($auth)->postJson('/api/v1/auth/staff/sessions/revoke-others')->assertOk()->assertJsonPath('data.revoked', 1);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$other}"])->getJson('/api/v1/auth/staff/profile')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $secret = $this->withHeaders($auth)->postJson('/api/v1/team/mfa/setup')->assertOk()->json('data.secret');
        $codes = $this->withHeaders($auth)->postJson('/api/v1/team/mfa/confirm', ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'nadine@spark.ci', 'password' => 'NouveauMotDePasse1'])
            ->assertStatus(422)->assertJsonPath('meta.mfa_required', true);
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'nadine@spark.ci', 'password' => 'NouveauMotDePasse1', 'otp' => '000000'])
            ->assertStatus(422)->assertJsonPath('errors.0.field', 'otp');
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'nadine@spark.ci', 'password' => 'NouveauMotDePasse1', 'otp' => Totp::code($secret)])
            ->assertOk();
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'nadine@spark.ci', 'password' => 'NouveauMotDePasse1', 'otp' => $codes[0]])
            ->assertOk();
    }
}
