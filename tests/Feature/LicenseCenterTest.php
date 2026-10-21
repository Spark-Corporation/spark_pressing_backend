<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class LicenseCenterTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs(Admin::query()->create([
            'fullname' => 'Aïcha N.',
            'email' => 'aicha@spark.local',
            'password' => 'password',
            'status' => true,
        ]));
    }

    private function makeLicense(array $attrs = []): License
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['name' => 'SPARK PRESSING Côte d’Ivoire'], ['country_code' => 'CI']);
        $this->makeStaff($agency);

        return License::query()->create(array_merge([
            'pressing_id' => $pressing->id,
            'plan' => 'business',
            'periodicity' => 'annual',
            'seats' => 100,
            'is_activated' => true,
            'activated_at' => now()->subDays(270),
            'period_started_at' => now()->subDays(270),
            'expires_at' => now()->addDays(94),
            'status' => true,
        ], $attrs));
    }

    public function test_index_and_overview_match_licence_center(): void
    {
        $license = $this->makeLicense();
        $this->actingAsSuperadmin();

        $this->getJson('/api/v1/superadmin/licenses?country=CI&status=active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', sprintf('LIC-CI-%05d', $license->id))
            ->assertJsonPath('data.0.plan.label', 'Business')
            ->assertJsonPath('data.0.periodicity.label', 'Annuel')
            ->assertJsonPath('data.0.status.code', 'active')
            ->assertJsonPath('data.0.days_left', 94)
            ->assertJsonPath('data.0.usage.agencies.limit', 6)
            ->assertJsonPath('data.0.usage.users.used', 1)
            ->assertJsonStructure(['data' => [['pressing' => ['initials', 'country'], 'period' => ['consumed_percent'], 'payment_status' => ['code', 'label']]]]);

        $this->getJson('/api/v1/superadmin/licenses?status=suspended')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/superadmin/licenses/overview?period=week')
            ->assertOk()
            ->assertJsonPath('data.kpis.active.value', 1)
            ->assertJsonPath('data.network.countries', 1)
            ->assertJsonStructure(['data' => [
                'kpis' => ['active', 'expiring_soon', 'suspended', 'renewals', 'revenue'],
                'deadlines' => ['window_days', 'items'],
                'forecast' => ['amount', 'renewals_expected', 'secured_percent'],
            ]]);
    }

    public function test_detail_renew_validate_and_invoice(): void
    {
        Storage::fake(config('filesystems.default'));
        $license = $this->makeLicense();
        $this->actingAsSuperadmin();

        $this->getJson("/api/v1/superadmin/licenses/{$license->id}")
            ->assertOk()
            ->assertJsonPath('data.renewal.offers.0.amount', 15000)
            ->assertJsonPath('data.renewal.offers.1.savings_percent', 11)
            ->assertJsonPath('data.renewal.offers.2.free_months', 2)
            ->assertJsonPath('data.renewal.offers.2.recommended', true)
            ->assertJsonPath('data.renewal.defaults.effective_at', $license->expires_at->copy()->addDay()->toDateString())
            ->assertJsonPath('data.coverage.agencies.0.users_count', 1)
            ->assertJsonPath('data.limits.items.1.limit', 100)
            ->assertJsonPath('data.actions.can_suspend', true);

        $detail = $this->post("/api/v1/superadmin/licenses/{$license->id}/renew", [
            'periodicity' => 'annual',
            'payment_method' => 'cash',
            'reference' => 'TRX-2026-09-2841',
            'auto_renew' => true,
            'proof' => UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.license.auto_renew', true)
            ->assertJsonPath('data.license.payment_status.code', 'pending')
            ->assertJsonPath('data.payments.0.total', 150000)
            ->assertJsonPath('data.payments.0.status.label', 'En attente')
            ->assertJsonPath('data.audit.last_action.actor', 'Aïcha N.')
            ->assertJsonPath('data.audit.checks.1.label', 'Justificatif en attente de validation')
            ->json('data');

        $paymentId = $detail['payments'][0]['id'];
        $this->assertTrue($license->fresh()->expires_at->gt(now()->addYear()));

        $this->postJson("/api/v1/superadmin/licenses/{$license->id}/payments/{$paymentId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'paid');

        $this->get("/api/v1/superadmin/licenses/{$license->id}/payments/{$paymentId}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->getJson('/api/v1/superadmin/licenses/overview')
            ->assertJsonPath('data.kpis.renewals.value', 1)
            ->assertJsonPath('data.kpis.revenue.value', 150000);
    }

    public function test_suspend_and_reactivate(): void
    {
        $license = $this->makeLicense();
        $this->actingAsSuperadmin();

        $this->postJson("/api/v1/superadmin/licenses/{$license->id}/suspend", ['reason' => 'Impayé'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Impayé');

        $this->postJson("/api/v1/superadmin/licenses/{$license->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'active');

        $this->getJson("/api/v1/superadmin/licenses/{$license->id}")
            ->assertJsonPath('data.audit.last_action.action', 'license.reactivated');
    }
}
