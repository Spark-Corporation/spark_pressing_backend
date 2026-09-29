<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\IdempotencyKey;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class IdempotencyAndRollbackTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_same_client_uuid_replays_deposit_without_duplicate(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);
        $uuid = (string) Str::uuid();

        Sanctum::actingAs($cashier);

        $first = $this->postJson('/api/v1/deposits', [
            'client_uuid' => $uuid,
            'client_id' => $client->id,
            'advanced' => 500,
            'payment_method' => 'cash',
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->assertCreated();

        $second = $this->postJson('/api/v1/deposits', [
            'client_uuid' => $uuid,
            'client_id' => $client->id,
            'advanced' => 500,
            'payment_method' => 'cash',
            'lines' => [['article_id' => $article->id, 'quantity' => 9, 'type_action' => 0]],
        ])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $second->json('data.units.0.quantity'));
    }

    public function test_wallet_failure_rolls_back_entire_deposit(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency, ['wallet_balance' => 100]);
        $article = $this->makeArticle($pressing, ['classic_price' => 5000]);

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/deposits', [
            'client_uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'advanced' => 5000,
            'payment_method' => 'wallet',
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->assertStatus(422);

        $this->assertSame(0, Deposit::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(100, $client->fresh()->wallet_balance);
    }

    public function test_idempotency_key_header_replays_same_response(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1500]);
        $key = 'pos-deposit-'.Str::uuid();

        Sanctum::actingAs($cashier);

        $payload = [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ];

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/deposits', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false');

        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/deposits', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(IdempotencyKey::STATUS_COMPLETED, IdempotencyKey::query()->where('key', $key)->value('status'));
    }

    public function test_failed_request_releases_idempotency_key_for_retry(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $article = $this->makeArticle($pressing);
        $key = 'retry-key-'.Str::uuid();

        Sanctum::actingAs($cashier);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/deposits', [
                'client_id' => 999999,
                'lines' => [['article_id' => $article->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('idempotency_keys', ['key' => $key]);

        $client = $this->makeClient($agency);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/deposits', [
                'client_id' => $client->id,
                'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
            ])
            ->assertCreated();
    }

    public function test_retrieve_is_idempotent_and_payment_uuid_does_not_double_charge(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 2000]);
        $payUuid = (string) Str::uuid();

        Sanctum::actingAs($cashier);

        $id = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 0,
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->json('data.id');

        $this->postJson("/api/v1/deposits/{$id}/payments", [
            'client_uuid' => $payUuid,
            'amount' => 2000,
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('data.left_to_pay', 0);

        $this->postJson("/api/v1/deposits/{$id}/payments", [
            'client_uuid' => $payUuid,
            'amount' => 2000,
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('data.left_to_pay', 0);

        $this->assertSame(1, Transaction::query()->where('deposit_id', $id)->count());

        $this->postJson("/api/v1/deposits/{$id}/retrieve", ['receiver_name' => 'A'])
            ->assertOk()
            ->assertJsonPath('data.status', false);

        $this->postJson("/api/v1/deposits/{$id}/retrieve", ['receiver_name' => 'B'])
            ->assertOk()
            ->assertJsonPath('data.status', false)
            ->assertJsonPath('data.receiver_name', 'A');
    }
}
