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
            ->assertJsonPath('data.operations.0.status', 'synced');

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

    public function test_offline_queue_conflicts_duplicates_and_resolution(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        Sanctum::actingAs($this->makeStaff($agency, 'manager'));
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 10000]);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];
        \App\Models\Promo::query()->create(['pressing_id' => $pressing->id, 'code' => 'RENTREE15', 'rate_percent' => 15, 'status' => false]);
        $line = ['client_id' => $client->id, 'advanced' => 2000, 'lines' => [['article_id' => $article->id, 'quantity' => 1]]];
        $op = fn (string $uuid, string $ref, array $extra = []) => ['type' => 'deposit', 'client_uuid' => $uuid, 'local_ref' => $ref, 'payload' => $line + $extra];

        $first = $this->withHeaders($headers)->postJson('/api/v1/sync', ['operations' => [$op('21111111-1111-1111-1111-111111111111', 'LOCAL-BGV-0041')]])
            ->assertOk()->assertJsonPath('data.operations.0.local_ref', 'LOCAL-BGV-0041');
        $code = $first->json('data.operations.0.server_ref');

        $sync = $this->withHeaders($headers)->postJson('/api/v1/sync', ['reference' => 'SYNC-0928-16H32', 'operations' => [
            $op('21111111-1111-1111-1111-111111111111', 'LOCAL-BGV-0041'),
            $op('31111111-1111-1111-1111-111111111111', 'LOCAL-BGV-0042', ['promo_code' => 'RENTREE15']),
        ]])->assertOk()
            ->assertJsonPath('data.operations.0.status', 'duplicate')
            ->assertJsonPath('data.operations.0.server_ref', $code)
            ->assertJsonPath('data.operations.1.status', 'conflict')
            ->assertJsonPath('data.operations.1.conflict.code', 'promo_unavailable')
            ->assertJsonCount(3, 'data.operations.1.conflict.options')
            ->assertJsonMissingPath('data.operations.1.payload')
            ->assertJsonPath('data.batch.status', 'partial')
            ->assertJsonPath('data.integrity.deduplication.duplicates', 1);
        $batch = $sync->json('data.batch.id');

        $this->withHeaders($headers)->postJson("/api/v1/sync/batches/{$batch}/operations/31111111-1111-1111-1111-111111111111/resolve", ['decision' => 'keep_discount'])
            ->assertOk()
            ->assertJsonPath('data.operation.status', 'synced')
            ->assertJsonPath('data.operation.decision', 'keep_discount')
            ->assertJsonPath('data.batch.status', 'completed');
        $this->assertSame(1500, (int) \App\Models\Deposit::query()->where('client_uuid', '31111111-1111-1111-1111-111111111111')->value('discount'));
    }
}
