<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 10 — Multi-agences ». */
class NetworkScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_consolidated_overview_and_agency_detail(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $agency->update(['opens_at' => '08:00', 'closes_at' => '19:00', 'monthly_revenue_target' => 20000]);
        $other = Agency::query()->create(['pressing_id' => $pressing->id, 'name' => 'Plateau', 'currency' => 'XOF', 'status' => true, 'code_prefix' => 'PL']);
        $admin = $this->makeStaff($agency, 'admin', ['fullname' => 'Aïcha Koné']);
        Sanctum::actingAs($admin);
        $article = $this->makeArticle($pressing, ['classic_price' => 9000]);
        $client = $this->makeClient($agency);

        $create = fn (Agency $a, int $qty, int $advance) => $this->withHeaders(['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $a->id])
            ->postJson('/api/v1/deposits', ['client_id' => $client->id, 'advanced' => $advance, 'lines' => [['article_id' => $article->id, 'quantity' => $qty]]])
            ->assertCreated()->json('data.id');
        $create($agency, 2, 18000);
        $late = $create($other, 1, 0);
        Deposit::query()->whereKey($late)->update(['retrieve_date' => now()->subHours(3)]);

        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];
        $this->withHeaders($headers)->getJson('/api/v1/network/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.revenue.value', 27000)
            ->assertJsonPath('data.kpis.deposits.value', 2)
            ->assertJsonPath('data.kpis.unpaid.value', 9000)
            ->assertJsonPath('data.ranking.0.agency_id', $agency->id)
            ->assertJsonPath('data.ranking.0.rank', 1)
            ->assertJsonPath('data.kpis.sync_health.agencies', 2)
            ->assertJsonPath('data.alerts.0.title', 'Plateau · impayés élevés')
            ->assertJsonPath('data.alerts.1.title', 'Plateau · 1 retards')
            ->assertJsonPath('data.alerts.2.type', 'objective')
            ->assertJsonCount(2, 'data.agencies');

        $this->withHeaders($headers)->getJson("/api/v1/network/agencies/{$agency->id}")
            ->assertOk()
            ->assertJsonPath('data.agency.opening_hours.label', 'ouverte aujourd’hui de 08:00 à 19:00')
            ->assertJsonPath('data.kpis.revenue.network_rank', 1)
            ->assertJsonPath('data.kpis.objective.percent', 90)
            ->assertJsonPath('data.kpis.objective.remaining', 2000)
            ->assertJsonPath('data.kpis.deposits_retrievals.deposits', 1)
            ->assertJsonPath('data.workshop.orders', 1)
            ->assertJsonPath('data.team.members', 1)
            ->assertJsonPath('data.history.0.type', 'deposit.created')
            ->assertJsonStructure(['data' => ['network_comparison', 'clients' => ['distribution'], 'delays_unpaid' => ['rows'], 'sync' => ['availability_percent']]]);
    }
}
