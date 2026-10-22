<?php

namespace Tests\Feature;

use App\Models\Deposit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 17.02 — Heures de retrait ». */
class RetrievalRuleScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_rules_preview_and_effect_on_new_deposits(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['hours_classic' => 72], ['name' => 'Bingerville']);
        Sanctum::actingAs($this->makeStaff($agency, 'admin'));
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $this->withHeaders($headers)->getJson("/api/v1/retrieval-rules/preview?agency_id={$agency->id}&service=express&hours=8&received_at=2026-10-15T10:00:00")
            ->assertOk()
            ->assertJsonPath('data.example.label', '15 oct. 10:00 → 15 oct. 18:00')
            ->assertJsonPath('data.valid', true);

        $id = $this->withHeaders($headers)->postJson('/api/v1/retrieval-rules', ['agency_id' => $agency->id, 'service' => 'classic', 'hours' => 48])
            ->assertCreated()
            ->assertJsonPath('data.service_label', 'Classique')
            ->assertJsonPath('data.hours_label', '48 h')
            ->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/retrieval-rules', ['agency_id' => $agency->id, 'service' => 'classic', 'hours' => 24])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/v1/retrieval-rules', ['service' => 'repass', 'hours' => 24])->assertCreated();

        $this->withHeaders($headers)->getJson('/api/v1/retrieval-rules?received_at=2026-10-15T10:00:00')
            ->assertOk()
            ->assertJsonPath('meta.active_rules', 2)
            ->assertJsonPath('data.0.agency', 'Bingerville')
            ->assertJsonPath('data.0.example.label', '15 oct. 10:00 → 17 oct. 10:00')
            ->assertJsonPath('data.1.agency', 'Toutes les agences');

        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);
        $depositId = $this->withHeaders($headers)->postJson('/api/v1/deposits', ['client_id' => $client->id, 'lines' => [['article_id' => $article->id, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');
        $deposit = Deposit::query()->find($depositId);
        $this->assertSame(48, (int) round(CarbonImmutable::parse($deposit->deposit_date)->diffInHours($deposit->retrieve_date)));

        $this->withHeaders($headers)->deleteJson("/api/v1/retrieval-rules/{$id}")
            ->assertOk()->assertJsonPath('data.message', fn ($m) => str_contains($m, 'Bingerville · Classique · 48 heures'));
    }
}
