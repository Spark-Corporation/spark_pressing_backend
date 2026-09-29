<?php

namespace Tests\Feature;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class TenantScopeRulesTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_admin_sees_all_agencies_of_own_pressing_only(): void
    {
        $this->seedRoles();
        [$pressing, $akwa] = $this->makePressingWithAgency();
        $bonaberi = Agency::factory()->create(['pressing_id' => $pressing->id]);
        [, $foreign] = $this->makePressingWithAgency();

        $admin = $this->makeStaff($akwa, 'admin');
        $cashierB = $this->makeStaff($bonaberi);
        $cashierF = $this->makeStaff($foreign);
        $article = $this->makeArticle($pressing);
        $foreignArticle = $this->makeArticle($foreign->pressing);
        $clientB = $this->makeClient($bonaberi);
        $clientF = $this->makeClient($foreign);

        Sanctum::actingAs($cashierB);
        $visibleId = $this->postJson('/api/v1/deposits', [
            'client_id' => $clientB->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        Sanctum::actingAs($cashierF);
        $hiddenId = $this->postJson('/api/v1/deposits', [
            'client_id' => $clientF->id,
            'lines' => [['article_id' => $foreignArticle->id, 'quantity' => 1]],
        ])->json('data.id');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/deposits')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visibleId);

        $this->getJson("/api/v1/deposits/{$hiddenId}")->assertStatus(404);

        $this->getJson('/api/v1/deposits', ['X-Agency-Id' => $bonaberi->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/deposits', ['X-Agency-Id' => $foreign->id])
            ->assertStatus(403);
    }

    public function test_cashier_cannot_switch_to_sibling_agency(): void
    {
        $this->seedRoles();
        [$pressing, $akwa] = $this->makePressingWithAgency();
        $bonaberi = Agency::factory()->create(['pressing_id' => $pressing->id]);
        $cashier = $this->makeStaff($akwa);
        $article = $this->makeArticle($pressing);
        $client = $this->makeClient($bonaberi);

        Sanctum::actingAs($cashier);

        $this->withHeaders(['X-Agency-Id' => $bonaberi->id])
            ->postJson('/api/v1/deposits', [
                'client_id' => $client->id,
                'lines' => [['article_id' => $article->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    public function test_consolidated_dashboard_is_admin_only_and_pressing_scoped(): void
    {
        $this->seedRoles();
        [$pressing, $akwa] = $this->makePressingWithAgency();
        Agency::factory()->create(['pressing_id' => $pressing->id]);
        $admin = $this->makeStaff($akwa, 'admin');
        $cashier = $this->makeStaff($akwa);

        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/reports/consolidated')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/reports/consolidated')
            ->assertOk()
            ->assertJsonPath('data.totals.deposits', 0);
        $this->assertCount(2, $this->getJson('/api/v1/reports/consolidated')->json('data.agencies'));
    }
}
