<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

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
            ->assertJsonPath('data.actor', 'superadmin')
            ->assertJsonPath('data.user.type', 'superadmin')
            ->assertJsonPath('data.user.status', true)
            ->assertJsonPath('data.user.roles.0', 'superadmin');
    }

    public function test_staff_login_by_phone_returns_contexts_and_expiry(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency([], ['name' => 'Cocody Angré']);
        $this->makeStaff($agency, 'manager', [
            'email' => 'nadia@test.local',
            'phone_number' => '0748221603',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/staff/login', [
            'login' => '0748221603',
            'password' => 'password',
            'agency_id' => $agency->id,
            'remember' => true,
        ])->assertOk()
            ->assertJsonPath('data.user.contexts.0.label', 'Cocody Angré • Manager')
            ->assertJsonPath('data.user.active_agency_id', $agency->id)
            ->assertJsonStructure(['data' => ['expires_at']]);
    }

    public function test_login_is_locked_after_five_failures(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $this->makeStaff($agency, 'caissier', ['email' => 'lock@test.local', 'password' => 'password']);

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/staff/login', ['login' => 'lock@test.local', 'password' => 'bad'])
                ->assertStatus(422)
                ->assertJsonPath('meta.attempts_remaining', 5 - $i);
        }

        $this->postJson('/api/v1/auth/staff/login', ['login' => 'lock@test.local', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'locked');
    }

    public function test_password_reset_with_six_digit_code(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'caissier', [
            'email' => 'reset@test.local',
            'phone_number' => '0700000042',
            'password' => 'password',
        ]);

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->postJson('/api/v1/auth/staff/password/channels', ['email' => 'reset@test.local'])
            ->assertOk()
            ->assertJsonPath('data.channels.1.destination', '•••• 42');

        $sms = new class extends \App\Support\Sms\SmsSender
        {
            public array $sent = [];

            public function send(string $phone, string $message): void
            {
                $this->sent[] = $message;
            }
        };
        $this->app->instance(\App\Support\Sms\SmsSender::class, $sms);

        $this->postJson('/api/v1/auth/staff/forgot-password', ['email' => 'reset@test.local', 'channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('data.expires_in', 600);

        preg_match('/(\d{6})/', $sms->sent[0] ?? '', $m);
        $code = $m[1];

        $this->postJson('/api/v1/auth/staff/verify-reset-code', ['email' => 'reset@test.local', 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertStatus(422);

        $token = $this->postJson('/api/v1/auth/staff/verify-reset-code', ['email' => 'reset@test.local', 'code' => $code])
            ->assertOk()
            ->json('data.reset_token');

        $this->postJson('/api/v1/auth/staff/reset-password', [
            'email' => 'reset@test.local',
            'reset_token' => $token,
            'password' => 'nouveau-pass',
            'password_confirmation' => 'nouveau-pass',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nouveau-pass', $user->fresh()->password));

        $this->postJson('/api/v1/auth/staff/forgot-password', ['email' => 'reset@test.local'])->assertOk();
        $this->postJson('/api/v1/auth/staff/forgot-password', ['email' => 'reset@test.local'])->assertStatus(429);
    }

    public function test_unauthenticated_api_returns_401(): void
    {
        $this->getJson('/api/v1/deposits')->assertStatus(401);
    }
}
