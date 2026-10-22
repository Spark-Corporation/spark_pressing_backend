<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Support\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 05 — Retraits ». */
class RetrievalScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    private array $headers;

    private function readyDeposit(string $role = 'admin', array $pressingAttrs = []): Deposit
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(array_merge(['block_retrieve_if_unpaid' => true], $pressingAttrs));
        $user = $this->makeStaff($agency, $role);
        $client = $this->makeClient($agency, ['fullname' => 'Aminata Diarra', 'phone_number' => '0748221903']);
        $article = $this->makeArticle($pressing, ['classic_price' => 2000]);

        Sanctum::actingAs($user);
        $this->headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];
        $this->app->instance(SmsSender::class, new class extends SmsSender
        {
            public function send(string $phone, string $message): void {}
        });

        $id = $this->withHeaders($this->headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 1000,
            'retrieve_date' => now()->addHour()->toIso8601String(),
            'lines' => [
                ['article_id' => $article->id, 'quantity' => 3],
                ['article_id' => $article->id, 'quantity' => 1, 'designation' => 'Robe délicate'],
            ],
        ])->assertCreated()->json('data.id');

        Deposit::query()->whereKey($id)->update(['etat' => Deposit::ETAT_CLASSED]);

        return Deposit::query()->find($id);
    }

    public function test_center_kpis_schedule_and_notification(): void
    {
        $deposit = $this->readyDeposit();

        $this->withHeaders($this->headers)->getJson('/api/v1/retrievals/center')
            ->assertOk()
            ->assertJsonPath('meta.kpis.ready_today.value', 1)
            ->assertJsonPath('meta.kpis.ready_today.pieces', 4)
            ->assertJsonPath('meta.kpis.awaiting_notification.value', 1)
            ->assertJsonPath('meta.kpis.unpaid_balances.value', 7000)
            ->assertJsonPath('meta.today_schedule.0.payment.label', 'À encaisser')
            ->assertJsonPath('data.0.notification.label', 'À notifier');

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/notify", ['channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('data.notification.label', 'SMS envoyé');
    }

    public function test_checkout_blocks_unpaid_then_partial_retrieve_with_payment(): void
    {
        $deposit = $this->readyDeposit();
        $units = $deposit->units()->orderBy('id')->get();

        $this->withHeaders($this->headers)->getJson("/api/v1/deposits/{$deposit->id}/checkout")
            ->assertOk()
            ->assertJsonPath('data.balance.blocked', true)
            ->assertJsonPath('data.balance.can_override', true)
            ->assertJsonPath('data.units.0.remaining', 3)
            ->assertJsonPath('data.steps.2.state', 'done')
            ->assertJsonPath('data.after_confirm.notify_sms.destination', '•••• 03');

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/checkout", [
            'units' => [['id' => $units[0]->id, 'quantity' => 3]],
        ])->assertStatus(422);

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/checkout", [
            'units' => [
                ['id' => $units[0]->id, 'quantity' => 3, 'verification' => 'compliant'],
                ['id' => $units[1]->id, 'quantity' => 0, 'verification' => 'control_required'],
            ],
            'payment' => ['amount' => 7000, 'method' => 'cash', 'received' => 10000],
            'condition' => 'compliant',
            'receiver_type' => 'client',
            'identity_confirmed' => true,
            'notify_sms' => true,
        ])->assertOk()
            ->assertJsonPath('data.partial', true)
            ->assertJsonPath('data.change_due', 3000)
            ->assertJsonPath('data.deposit.left_to_pay', 0);

        $this->assertSame('control_required', $units[1]->fresh()->verification);
        $this->assertNull($deposit->fresh()->retrieved_at);
    }

    public function test_manager_override_allows_unpaid_retrieve(): void
    {
        $deposit = $this->readyDeposit('manager');

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/checkout", [
            'override_reason' => 'Client fidèle, règlement demain',
            'receiver_type' => 'third_party',
            'receiver_name' => 'Moussa Diarra',
        ])->assertOk()
            ->assertJsonPath('data.partial', false);

        $fresh = $deposit->fresh();
        $this->assertNotNull($fresh->retrieved_at);
        $this->assertSame('Moussa Diarra', $fresh->receiver_name);
        $this->assertSame('Client fidèle, règlement demain', $fresh->retrieve_override_reason);
    }

    public function test_aged_deposits_buckets_and_actions(): void
    {
        $deposit = $this->readyDeposit();
        Deposit::query()->whereKey($deposit->id)->update(['retrieve_date' => now()->subDays(40)]);

        $this->withHeaders($this->headers)->getJson('/api/v1/retrievals/aged')
            ->assertOk()
            ->assertJsonPath('data.to_handle', 1)
            ->assertJsonPath('data.buckets.30_60.count', 1)
            ->assertJsonPath('data.items.0.decision.code', 'remind');

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/aged-action", ['action' => 'storage_fee', 'amount' => 500])
            ->assertOk()
            ->assertJsonPath('data.total', 8500);

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$deposit->id}/aged-action", ['action' => 'handled'])->assertOk();
        $this->withHeaders($this->headers)->getJson('/api/v1/retrievals/aged')->assertJsonPath('data.to_handle', 0);
    }
}
