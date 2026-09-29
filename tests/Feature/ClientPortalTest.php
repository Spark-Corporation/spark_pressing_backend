<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_client_sees_only_own_deposits(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency, [
            'email' => 'jean@test.local',
            'phone_number' => '690111222',
            'password' => 'password',
        ]);
        $other = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($cashier);
        $ownId = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');
        $otherId = $this->postJson('/api/v1/deposits', [
            'client_id' => $other->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        Sanctum::actingAs($client);

        $this->getJson('/api/v1/client/deposits')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownId);

        $this->getJson("/api/v1/client/deposits/{$ownId}")->assertOk();
        $this->getJson("/api/v1/client/deposits/{$otherId}")->assertStatus(403);
    }
}
