<?php

namespace Tests\Feature;

use App\Models\LoyalGroup;
use App\Models\Promo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class LoyaltyAndPromoTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_payment_earns_loyalty_points(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['loyalty_points_rate' => 10]);
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 10000]);

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 10000,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.points_earned', 100);

        $this->getJson("/api/v1/clients/{$client->id}/loyalty")
            ->assertOk()
            ->assertJsonPath('data.loyalty_points', 100);
    }

    public function test_promo_applies_only_inside_pressing(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        [$other] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Promo::query()->create([
            'pressing_id' => $pressing->id,
            'code' => 'ETE10',
            'rate_percent' => 10,
            'status' => true,
        ]);
        Promo::query()->create([
            'pressing_id' => $other->id,
            'code' => 'OTHER50',
            'rate_percent' => 50,
            'status' => true,
        ]);

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'promo_code' => 'ETE10',
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.total', 900);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'promo_code' => 'OTHER50',
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }
}
