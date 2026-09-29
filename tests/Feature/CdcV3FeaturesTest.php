<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\ExchangeRate;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class CdcV3FeaturesTest extends TestCase
{
    use RefreshDatabase, CreatesSparkFixtures;

    public function test_agency_currency_and_qr_token_on_deposit(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(
            ['qr_labels_enabled' => true],
            ['currency' => 'GHS']
        );
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($cashier);

        $res = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 0,
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->assertCreated();

        $res->assertJsonPath('data.currency', 'GHS');
        $this->assertNotEmpty($res->json('data.qr_token'));

        $token = $res->json('data.qr_token');
        $this->getJson('/api/v1/deposits/qr/'.$token)
            ->assertOk()
            ->assertJsonPath('data.id', $res->json('data.id'));
    }

    public function test_agency_price_overrides_article_columns(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);
        $service = Service::query()->where('pressing_id', $pressing->id)->where('code', 'classic')->firstOrFail();
        $this->makeAgencyPrice($agency, $article, $service, 2500);

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [[
                'article_id' => $article->id,
                'service_id' => $service->id,
                'quantity' => 1,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.total', 2500)
            ->assertJsonPath('data.units.0.service_id', $service->id);
    }

    public function test_unlimited_custom_service(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $manager = $this->makeStaff($agency, 'manager');
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($manager);
        $serviceRes = $this->postJson('/api/v1/services', [
            'name' => 'Nettoyage à sec premium',
            'code' => 'dryclean',
            'default_hours' => 72,
        ])->assertCreated();

        $serviceId = $serviceRes->json('data.id');
        $this->assertNotNull($serviceId);

        $priceRes = $this->postJson('/api/v1/agency-prices', [
            'agency_id' => $agency->id,
            'article_id' => $article->id,
            'service_id' => $serviceId,
            'amount_minor' => 8000,
            'valid_from' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $this->assertSame(8000, $priceRes->json('data.amount_minor'));
        $this->assertDatabaseHas('agency_prices', [
            'service_id' => $serviceId,
            'amount_minor' => 8000,
            'agency_id' => $agency->id,
        ]);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [[
                'article_id' => $article->id,
                'service_id' => $serviceId,
                'quantity' => 1,
            ]],
        ])->assertCreated()->assertJsonPath('data.total', 8000);
    }

    public function test_livreur_marks_delivered_with_mobile_collection(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['delivery_fee' => 500]);
        $cashier = $this->makeStaff($agency);
        $livreur = $this->makeStaff($agency, 'livreur');
        $manager = $this->makeStaff($agency, 'manager');
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 2000]);

        Sanctum::actingAs($cashier);
        $depositId = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'with_delivery' => true,
            'advanced' => 500,
            'payment_method' => 'cash',
            'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->makeStaff($agency, 'laveur'));
        $this->postJson("/api/v1/deposits/{$depositId}/workshop", ['etat' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/deposits/{$depositId}/workshop", ['etat' => 'treated'])->assertOk();

        Sanctum::actingAs($this->makeStaff($agency, 'classeur'));
        $this->postJson("/api/v1/deposits/{$depositId}/workshop", ['etat' => 'classed'])->assertOk();

        Sanctum::actingAs($manager);
        $zoneId = $this->postJson('/api/v1/deliveries/zones', [
            'name' => 'Zone Akwa',
            'code' => 'AK',
            'agency_id' => $agency->id,
        ])->assertCreated()->json('data.id');

        $roundId = $this->postJson('/api/v1/deliveries/rounds', [
            'agency_id' => $agency->id,
            'livreur_id' => $livreur->id,
            'delivery_zone_id' => $zoneId,
            'round_date' => now()->toDateString(),
            'name' => 'Tournée matin',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/deliveries/rounds/{$roundId}/assign", [
            'deposit_ids' => [$depositId],
        ])->assertOk();

        Sanctum::actingAs($livreur);
        $this->getJson('/api/v1/deliveries/queue?mine=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $depositId);

        $deposit = Deposit::query()->findOrFail($depositId);
        $left = (int) $deposit->left_to_pay;

        $this->postJson("/api/v1/deliveries/deposits/{$depositId}/deliver", [
            'confirmation_type' => 'code',
            'confirmation_value' => '1234',
            'collect_amount' => $left,
            'payment_method' => 'cash',
            'receiver_name' => 'Jean Client',
        ])->assertOk()
            ->assertJsonPath('data.delivery_status', 'delivered')
            ->assertJsonPath('data.left_to_pay', 0)
            ->assertJsonPath('data.status', false);

        $this->getJson('/api/v1/deliveries/history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $depositId);
    }

    public function test_multi_currency_consolidation_uses_dated_rate(): void
    {
        $this->seedRoles();
        [$pressing, $agencyXaf] = $this->makePressingWithAgency(
            ['reporting_currency' => 'XAF'],
            ['currency' => 'XAF', 'name' => 'Douala']
        );
        $agencyGhs = \App\Models\Agency::factory()->create([
            'pressing_id' => $pressing->id,
            'currency' => 'GHS',
            'name' => 'Accra',
            'code_prefix' => 'AC',
        ]);

        $manager = $this->makeStaff($agencyXaf, 'manager');
        $cashierGhs = $this->makeStaff($agencyGhs);
        $client = $this->makeClient($agencyGhs);
        $article = $this->makeArticle($pressing, ['classic_price' => 100]);

        ExchangeRate::query()->create([
            'pressing_id' => $pressing->id,
            'source_currency' => 'GHS',
            'target_currency' => 'XAF',
            'rate' => 60,
            'rate_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        Sanctum::actingAs($cashierGhs);
        $this->withHeader('X-Agency-Id', (string) $agencyGhs->id)
            ->postJson('/api/v1/deposits', [
                'client_id' => $client->id,
                'advanced' => 100,
                'payment_method' => 'cash',
                'lines' => [['article_id' => $article->id, 'quantity' => 1, 'type_action' => 0]],
            ])->assertCreated();

        Sanctum::actingAs($manager);
        $report = $this->getJson('/api/v1/reports/consolidated?target_currency=XAF')->assertOk();
        $agencies = collect($report->json('data.agencies'));
        $accra = $agencies->firstWhere('agency_id', $agencyGhs->id);
        $this->assertSame('GHS', $accra['currency']);
        $this->assertSame(100, $accra['receipts']);
        $this->assertArrayNotHasKey('fx_error', $accra['converted'] ?? [], json_encode($accra));
        $this->assertSame(6000, $accra['converted']['receipts']);
        $this->assertEquals(60, $accra['converted']['rate']);
    }

    public function test_price_history_does_not_alter_past_amount(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $manager = $this->makeStaff($agency, 'manager');
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);
        $service = Service::query()->where('pressing_id', $pressing->id)->where('code', 'classic')->firstOrFail();

        $this->makeAgencyPrice($agency, $article, $service, 1000, [
            'valid_from' => now()->subDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($cashier);
        $id = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'service_id' => $service->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/agency-prices', [
            'agency_id' => $agency->id,
            'article_id' => $article->id,
            'service_id' => $service->id,
            'amount_minor' => 9999,
            'valid_from' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame(1000, Deposit::query()->findOrFail($id)->total);
    }
}
