<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 17.03 — États du linge ». */
class LaundryStatusScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_defect_referential_usage_and_deactivation(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        Sanctum::actingAs($this->makeStaff($agency, 'admin'));
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);

        $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'lines' => [['article_id' => $article->id, 'quantity' => 1, 'defects' => ['stain']]],
        ])->assertCreated();

        $rows = $this->withHeaders($headers)->getJson('/api/v1/laundry-statuses')
            ->assertOk()
            ->assertJsonPath('meta.summary_label', '6 états · 6 actifs')
            ->assertJsonPath('data.0.title', 'Taché')
            ->assertJsonPath('data.0.usage_label', '1 dépôt')
            ->assertJsonPath('data.0.deletable', false)
            ->json('data');

        $stain = $rows[0]['id'];
        $this->withHeaders($headers)->deleteJson("/api/v1/laundry-statuses/{$stain}")->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/v1/laundry-statuses/{$stain}", ['status' => false])
            ->assertOk()->assertJsonPath('data.status_label', 'Inactif')
            ->assertJsonPath('meta.message', fn ($m) => str_contains($m, 'historique (1 dépôt)'));

        $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'lines' => [['article_id' => $article->id, 'quantity' => 1, 'defects' => ['stain']]],
        ])->assertStatus(422);

        $this->withHeaders($headers)->getJson('/api/v1/laundry-statuses/preview?title=déchiré')->assertJsonPath('data.valid', false);
        $new = $this->withHeaders($headers)->postJson('/api/v1/laundry-statuses', ['title' => 'Froissé', 'description' => 'Plis marqués'])
            ->assertCreated()->assertJsonPath('data.code', 'froisse')->json('data.id');

        $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'lines' => [['article_id' => $article->id, 'quantity' => 1, 'defects' => ['froisse']]],
        ])->assertCreated();
        $this->withHeaders($headers)->getJson('/api/v1/laundry-statuses?availability=inactive')->assertJsonCount(1, 'data');
        $this->assertNotNull($new);
    }
}
