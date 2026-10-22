<?php

namespace Tests\Feature;

use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 14 — Mobile client ». */
class ClientAppTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_home_deposits_tracking_loyalty_and_profile(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['loyalty_points_rate' => 10]);
        $agency->update(['contact' => '+225 27 20 65 18 20']);
        $cashier = $this->makeStaff($agency, 'caissier');
        $client = $this->makeClient($agency, ['fullname' => 'Aminata Koné', 'phone_number' => '+2250708421690', 'password' => 'password', 'loyalty_points' => 1240]);
        $article = $this->makeArticle($pressing, ['classic_price' => 7000]);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        Sanctum::actingAs($cashier);
        $active = $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'advanced' => 10000, 'retrieve_date' => now()->addDay()->setTime(17, 0)->toIso8601String(),
            'lines' => [['article_id' => $article->id, 'quantity' => 2, 'designation' => 'Chemises blanches']],
        ])->assertCreated()->json('data.id');
        $old = $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'advanced' => 7000, 'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        Deposit::query()->whereKey($old)->update(['status' => false, 'retrieved_at' => now(), 'deposit_date' => now()->subDays(10)]);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/client/login', ['login' => '+2250708421690', 'phone_number' => '+2250708421690', 'password' => 'password'])->json('data.token');
        Sanctum::actingAs($client->fresh());

        $this->getJson('/api/v1/client/app/home')
            ->assertOk()
            ->assertJsonPath('data.greeting', 'Bonjour Aminata')
            ->assertJsonPath('data.active_deposit.id', $active)
            ->assertJsonPath('data.active_deposit.steps.0.label', 'Reçu')
            ->assertJsonPath('data.tiles.left_to_pay', 4000)
            ->assertJsonPath('data.tiles.next_retrieval.label', 'Demain · 17:00')
            ->assertJsonPath('data.quick_actions.call', '+225 27 20 65 18 20')
            ->assertJsonPath('data.latest_notification.title', 'Votre dépôt est enregistré');

        $this->getJson('/api/v1/client/app/deposits')
            ->assertOk()
            ->assertJsonPath('meta.filters.0.count', 2)
            ->assertJsonPath('meta.filters.3.count', 1)
            ->assertJsonPath('data.0.payment_label', 'Acompte');
        $this->getJson('/api/v1/client/app/deposits?status=retrieved')->assertJsonPath('data.0.status_label', 'Retiré');

        $this->getJson("/api/v1/client/app/deposits/{$active}")
            ->assertOk()
            ->assertJsonPath('data.articles.lines.0.label', 'Chemises blanches')
            ->assertJsonPath('data.payment.left_to_pay', 4000)
            ->assertJsonPath('data.agency.phone', '+225 27 20 65 18 20');
        $this->getJson("/api/v1/client/app/deposits/{$old}")->assertOk();

        $this->getJson('/api/v1/client/app/loyalty')
            ->assertOk()
            ->assertJsonPath('data.tier.code', 'argent')
            ->assertJsonPath('data.next_reward.points', 2000)
            ->assertJsonCount(4, 'data.rules');

        $this->putJson('/api/v1/client/app/profile', ['address' => 'Cocody Riviera 3', 'communication' => ['personalized_offers' => true, 'sms' => true]])
            ->assertOk()
            ->assertJsonPath('data.identity.address', 'Cocody Riviera 3')
            ->assertJsonPath('data.communication.personalized_offers', true);
        $this->putJson('/api/v1/client/app/password', ['current_password' => 'password', 'password' => 'Nouveau1', 'password_confirmation' => 'Nouveau1'])->assertOk();
        $this->get('/api/v1/client/app/data-export')->assertOk()->assertHeader('content-disposition', 'attachment; filename="mes-donnees-spark.json"');

        $this->postJson('/api/v1/client/app/account/delete', ['password' => 'Nouveau1'])->assertStatus(422);
        $this->assertNotNull($token);
    }
}
