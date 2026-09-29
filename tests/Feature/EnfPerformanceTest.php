<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class EnfPerformanceTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_deposit_with_20_lines_completes_under_two_seconds(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);

        $articles = [];
        for ($i = 0; $i < 20; $i++) {
            $articles[] = $this->makeArticle($pressing, [
                'name' => "Article {$i}",
                'classic_price' => 1000 + $i,
            ]);
        }

        Sanctum::actingAs($cashier);

        $lines = array_map(fn ($article) => [
            'article_id' => $article->id,
            'quantity' => 1,
            'type_action' => 0,
        ], $articles);

        $started = microtime(true);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => $lines,
        ])->assertCreated()
            ->assertJsonPath('data.units.19.article_id', $articles[19]->id);

        $elapsed = microtime(true) - $started;

        $this->assertLessThan(2.0, $elapsed, "Création dépôt 20 lignes: {$elapsed}s (ENF01 < 2s)");
    }
}
