<?php

namespace Tests\Feature;

use App\Models\SyncBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 12 — Synchronisation ». */
class SyncScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_sync_center_and_batch_from_pos_sync(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 5000]);
        Sanctum::actingAs($admin);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $sync = $this->withHeaders($headers)->postJson('/api/v1/sync', [
            'device_code' => 'CAISSE-01',
            'operations' => [[
                'type' => 'deposit',
                'client_uuid' => '11111111-1111-1111-1111-111111111111',
                'payload' => [
                    'client_id' => $client->id,
                    'advanced' => 5000,
                    'lines' => [['article_id' => $article->id, 'quantity' => 1]],
                ],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.batch.status', 'completed')
            ->assertJsonPath('data.batch.device_code', 'CAISSE-01')
            ->assertJsonPath('data.operations.0.status', 'succeeded');

        $this->assertDatabaseCount('sync_batches', 1);
        $this->assertSame(1, (int) SyncBatch::query()->first()->succeeded);

        $this->withHeaders($headers)->getJson('/api/v1/sync/center')
            ->assertOk()
            ->assertJsonPath('data.kpis.online_agencies.total', 1)
            ->assertJsonPath('data.batches.0.reference', $sync->json('data.batch.reference'))
            ->assertJsonStructure(['data' => ['agencies', 'devices', 'alerts']]);

        $this->withHeaders($headers)->getJson('/api/v1/pos/sync/status')
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.last_batch.device_code', 'CAISSE-01');
    }
}
