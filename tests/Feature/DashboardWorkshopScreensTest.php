<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 00 — Vue d’ensemble » et « 04 — Atelier ». */
class DashboardWorkshopScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_dashboard_and_kanban_payloads(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['workshop_capacity' => 4]);
        $user = $this->makeStaff($agency, 'admin', ['fullname' => 'Jean Koffi']);
        $client = $this->makeClient($agency, ['fullname' => 'Mariam Traoré']);
        $article = $this->makeArticle($pressing, ['classic_price' => 1500]);

        Sanctum::actingAs($user);
        $headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];

        $id = $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 1000,
            'payment_method' => 'cash',
            'retrieve_date' => now()->subMinutes(30)->toIso8601String(),
            'lines' => [['article_id' => $article->id, 'quantity' => 3, 'defects' => ['stain']]],
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'in_progress'])->assertOk();

        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.recent_activity.0.title', 'Commande passée à l’étape « En lavage »')
            ->assertJsonPath('data.recent_activity.0.subtitle', fn ($s) => str_contains($s, 'Opérateur : Jean Koffi'))
            ->assertJsonPath('data.recent_activity.1.amount_type', 'credit')
            ->assertJsonPath('data.cash.status.label', 'Caisse ouverte')
            ->assertJsonPath('data.cash.inflows', 1000)
            ->assertJsonPath('data.unpaid_aging.overdue_count', 1)
            ->assertJsonStructure(['data' => [
                'kpis' => ['ready_to_notify', 'loyalty_clients_delta_pct'],
                'week' => ['revenue_delta_pct'],
                'agencies' => [['revenue_week', 'revenue_week_delta_pct', 'revenue_week_share_pct']],
                'workshop_pipeline' => ['due_soon_count', 'columns' => [['title']]],
                'unpaid_aging' => ['due_today_count'],
            ]]);

        $this->withHeaders($headers)->getJson('/api/v1/deposits/workshop?q=Mariam')
            ->assertOk()
            ->assertJsonPath('data.capacity.in_progress', 1)
            ->assertJsonPath('data.capacity.capacity', 4)
            ->assertJsonPath('data.columns.1.title', 'En cours')
            ->assertJsonPath('data.columns.1.overdue_count', 1)
            ->assertJsonPath('data.board.cours.0.priority.label', 'Urgent')
            ->assertJsonPath('data.board.cours.0.defects_count', 1)
            ->assertJsonPath('data.board.cours.0.responsable.initials', 'JK');

        $this->withHeaders($headers)->getJson("/api/v1/deposits/{$id}/workshop")
            ->assertOk()
            ->assertJsonPath('data.articles.0.note', 'Taché')
            ->assertJsonPath('data.responsables.laveur.fullname', 'Jean Koffi')
            ->assertJsonPath('data.next_action.label', 'Passer à « Traités »');

        $this->withHeaders($headers)->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'treated'])->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'classed', 'storage_location' => 'Rayon B · casier 08'])
            ->assertOk()
            ->assertJsonPath('data.storage_location', 'Rayon B · casier 08');
    }
}
