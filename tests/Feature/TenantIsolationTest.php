<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_staff_cannot_see_another_agency_deposits(): void
    {
        $this->seedRoles();
        [$pressing, $akwa] = $this->makePressingWithAgency();
        $bonaberi = \App\Models\Agency::factory()->create(['pressing_id' => $pressing->id]);

        $cashierA = $this->makeStaff($akwa);
        $cashierB = $this->makeStaff($bonaberi);
        $clientB = $this->makeClient($bonaberi);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($cashierB);
        $depositId = $this->postJson('/api/v1/deposits', [
            'client_id' => $clientB->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        Sanctum::actingAs($cashierA);

        $this->getJson('/api/v1/deposits')->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/deposits/{$depositId}")->assertStatus(404);
    }

    public function test_staff_cannot_see_another_pressing_clients(): void
    {
        $this->seedRoles();
        [, $agencyA] = $this->makePressingWithAgency();
        [, $agencyB] = $this->makePressingWithAgency();

        $cashierA = $this->makeStaff($agencyA);
        $clientB = $this->makeClient($agencyB, ['fullname' => 'Secret Client']);

        Sanctum::actingAs($cashierA);

        $this->getJson('/api/v1/clients?q=Secret')->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/clients/{$clientB->id}")->assertStatus(404);
    }

    public function test_washer_cannot_create_deposit(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $washer = $this->makeStaff($agency, 'laveur');
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($washer);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertStatus(403);
    }
}
