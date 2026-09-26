<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class DepositFlowTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_cashier_creates_pays_and_retrieves_a_deposit(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($cashier);

        $create = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 500,
            'payment_method' => 'cash',
            'lines' => [
                ['article_id' => $article->id, 'quantity' => 2, 'type_action' => 0],
            ],
        ])->assertCreated();

        $create->assertJsonPath('data.total', 2000)
            ->assertJsonPath('data.advanced', 500)
            ->assertJsonPath('data.left_to_pay', 1500)
            ->assertJsonPath('data.etat', 'waiting')
            ->assertJsonPath('data.status', true);

        $id = $create->json('data.id');
        $this->assertNotEmpty($create->json('data.code'));
        $this->assertNotEmpty($create->json('data.client_uuid'));

        $this->postJson("/api/v1/deposits/{$id}/payments", [
            'amount' => 1500,
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.left_to_pay', 0);

        $this->postJson("/api/v1/deposits/{$id}/retrieve", [
            'receiver_name' => 'Jean',
        ])->assertOk()
            ->assertJsonPath('data.status', false)
            ->assertJsonPath('data.receiver_name', 'Jean');
    }

    public function test_retrieve_is_blocked_when_unpaid(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($cashier);

        $id = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 0,
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->json('data.id');

        $this->postJson("/api/v1/deposits/{$id}/retrieve")
            ->assertStatus(422);
    }

    public function test_duplicate_client_uuid_is_idempotent(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);
        $uuid = '11111111-1111-1111-1111-111111111111';

        Sanctum::actingAs($cashier);

        $first = $this->postJson('/api/v1/deposits', [
            'client_uuid' => $uuid,
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated();

        $second = $this->postJson('/api/v1/deposits', [
            'client_uuid' => $uuid,
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $second->json('data.units.0.quantity'));
    }

    public function test_workshop_skips_laveur_when_disabled(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency([
            'workflow_laveur_enabled' => false,
            'workflow_classeur_enabled' => true,
        ]);
        $cashier = $this->makeStaff($agency, 'admin');
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($cashier);

        $id = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 1000,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        $this->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'in_progress'])
            ->assertStatus(422);

        $this->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'treated'])
            ->assertOk()
            ->assertJsonPath('data.etat', 'treated');
    }
}
