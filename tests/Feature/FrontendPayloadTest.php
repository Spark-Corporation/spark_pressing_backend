<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class FrontendPayloadTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_dashboard_returns_network_aggregate(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'manager');

        Sanctum::actingAs($user);

        $this->withHeaders([
            'X-Pressing-Id' => $agency->pressing_id,
            'X-Agency-Id' => $agency->id,
        ])->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'currency',
                    'kpis' => [
                        'revenue_today',
                        'deposits_today',
                        'retrieves_today',
                        'loyalty_clients',
                    ],
                    'series_7d',
                    'agencies',
                    'workshop_pipeline' => ['columns', 'due_soon'],
                    'cash' => ['by_payment_method'],
                    'unpaid_aging' => ['buckets'],
                    'recent_activity',
                    'sync',
                    'scope',
                    // rétrocompat
                    'deposits_today',
                    'receipts_today',
                ],
            ]);
    }

    public function test_bootstrap_includes_user_agency_permissions_and_sync(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'caissier');

        Sanctum::actingAs($user);

        $this->withHeaders([
            'X-Pressing-Id' => $agency->pressing_id,
            'X-Agency-Id' => $agency->id,
        ])->getJson('/api/v1/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.agency.id', $agency->id)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'fullname', 'email', 'roles'],
                    'agency',
                    'agencies',
                    'permissions',
                    'articles',
                    'services',
                    'clients',
                    'sync' => ['online', 'last_sync_at', 'pending_ops'],
                ],
            ]);
    }

    public function test_deposits_list_exposes_denormalized_fields(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'caissier');
        $client = $this->makeClient($agency, ['fullname' => 'Ada Client', 'phone_number' => '690000099']);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($user);
        $headers = [
            'X-Pressing-Id' => $agency->pressing_id,
            'X-Agency-Id' => $agency->id,
        ];

        $id = $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 0,
            'lines' => [['article_id' => $article->id, 'quantity' => 2, 'type_action' => 0]],
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->getJson('/api/v1/deposits')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.client_name', 'Ada Client')
            ->assertJsonPath('data.0.client_phone', '690000099')
            ->assertJsonPath('data.0.etat_label', 'À trier')
            ->assertJsonPath('data.0.colonne', 'attente')
            ->assertJsonPath('data.0.agency.id', $agency->id)
            ->assertJsonPath('data.0.articles_label', '2 pièces');
    }

    public function test_workshop_board_and_agencies_overview_and_sync_status(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $user = $this->makeStaff($agency, 'admin');

        Sanctum::actingAs($user);
        $headers = [
            'X-Pressing-Id' => $agency->pressing_id,
            'X-Agency-Id' => $agency->id,
        ];

        $this->withHeaders($headers)->getJson('/api/v1/deposits/workshop')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'columns',
                    'board' => ['attente', 'cours', 'traites', 'classes'],
                ],
            ]);

        $this->withHeaders($headers)->getJson('/api/v1/org/agencies/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'active_count',
                    'agencies' => [
                        ['id', 'name', 'slug', 'revenue_today', 'deposits_today'],
                    ],
                ],
            ]);

        $this->withHeaders($headers)->getJson('/api/v1/pos/sync/status')
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.pending_ops', 0);
    }

    public function test_clients_include_agency(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency([], ['name' => 'Akwa Test']);
        $user = $this->makeStaff($agency, 'caissier');
        $this->makeClient($agency, ['fullname' => 'Bob']);

        Sanctum::actingAs($user);

        $this->withHeaders([
            'X-Pressing-Id' => $agency->pressing_id,
            'X-Agency-Id' => $agency->id,
        ])->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonPath('data.0.agency.id', $agency->id)
            ->assertJsonPath('data.0.agency.name', 'Akwa Test');
    }
}
