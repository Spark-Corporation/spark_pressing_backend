<?php

namespace Tests\Feature;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 17.01 — Suffixes des codes dépôt ». */
class CodeSuffixScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_suffixes_per_agency_apply_to_future_deposits_only(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency([], ['name' => 'Bingerville', 'code_prefix' => 'BG', 'code_suffix' => null]);
        Agency::query()->create(['pressing_id' => $pressing->id, 'name' => 'Plateau', 'code_prefix' => 'PL', 'currency' => 'XOF', 'status' => true]);
        Sanctum::actingAs($this->makeStaff($agency, 'admin'));
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);
        $deposit = fn () => $this->withHeaders($headers)->postJson('/api/v1/deposits', ['client_id' => $client->id, 'lines' => [['article_id' => $article->id, 'quantity' => 1]]])->json('data.code');

        $before = $deposit();

        $this->withHeaders($headers)->getJson("/api/v1/code-suffixes/preview?agency_id={$agency->id}&suffix=-26")
            ->assertOk()->assertJsonPath('data.preview', 'BG-002684-26')->assertJsonPath('data.valid', true);
        $this->withHeaders($headers)->postJson('/api/v1/code-suffixes', ['agency_id' => $agency->id, 'title' => 'a b'])->assertStatus(422);

        $id = $this->withHeaders($headers)->postJson('/api/v1/code-suffixes', ['agency_id' => $agency->id, 'title' => '-26'])
            ->assertCreated()
            ->assertJsonPath('data.suffix', '26')
            ->assertJsonPath('data.example_code', 'BG-002684-26')
            ->json('data.suffix_id');
        $this->withHeaders($headers)->postJson('/api/v1/code-suffixes', ['agency_id' => $agency->id, 'title' => '27'])->assertStatus(422);

        $this->withHeaders($headers)->getJson('/api/v1/code-suffixes?q=plat')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.suffix', null);
        $this->withHeaders($headers)->getJson('/api/v1/code-suffixes')->assertJsonPath('meta.configured', 1);

        $after = $deposit();
        $this->assertStringEndsWith('-26', $after);
        $this->assertStringEndsNotWith('-26', $before);

        $this->withHeaders($headers)->deleteJson("/api/v1/code-suffixes/{$id}")
            ->assertOk()->assertJsonPath('data.agency.suffix', null);
        $this->assertStringEndsNotWith('-26', $deposit());
    }
}
