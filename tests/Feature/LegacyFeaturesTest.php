<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

class LegacyFeaturesTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_due_and_retrieved_lists(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($cashier);

        $dueId = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'retrieve_date' => now()->subDay()->toDateTimeString(),
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        $paidId = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 1000,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        $this->postJson("/api/v1/deposits/{$paidId}/retrieve", ['receiver_name' => 'Jean'])->assertOk();

        $this->getJson('/api/v1/deposits/due')->assertOk()
            ->assertJsonPath('data.0.id', $dueId);

        $this->getJson('/api/v1/deposits/retrieved')->assertOk()
            ->assertJsonPath('data.0.id', $paidId);
    }

    public function test_inactive_clients_and_state(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');
        $inactive = $this->makeClient($agency, ['fullname' => 'Ancien']);
        $active = $this->makeClient($agency, ['fullname' => 'Actif']);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/deposits', [
            'client_id' => $active->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson('/api/v1/clients/inactive?inactive_days=1')->assertOk()
            ->assertJsonFragment(['fullname' => 'Ancien']);

        $this->getJson("/api/v1/clients/{$active->id}/state")->assertOk()
            ->assertJsonPath('data.deposits', 1);
    }

    public function test_wallet_and_promo_special(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');
        $client = $this->makeClient($agency, ['wallet_balance' => 2000]);
        $article = $this->makeArticle($pressing, ['classic_price' => 1000]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/promo-specials', [
            'code' => 'VIP20',
            'rate_percent' => 20,
        ])->assertCreated();

        $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'promo_special_code' => 'VIP20',
            'advanced' => 800,
            'payment_method' => 'wallet',
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.total', 800)
            ->assertJsonPath('data.left_to_pay', 0);

        $this->assertSame(1200, $client->fresh()->wallet_balance);
    }

    public function test_cash_validate_and_reports(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');

        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/cash-movements', [
            'label' => 'Fond de caisse',
            'type' => 'in',
            'amount' => 5000,
        ])->assertCreated()
            ->assertJsonPath('data.validated', false)
            ->json('data.id');

        $this->postJson("/api/v1/cash-movements/{$id}/validate")->assertOk()
            ->assertJsonPath('data.validated', true);

        $this->getJson('/api/v1/reports/sales')->assertOk()->assertJsonStructure(['data' => ['count', 'total']]);
        $this->getJson('/api/v1/reports/discounts')->assertOk();
        $this->getJson('/api/v1/reports/daily-balance')->assertOk();
        $this->getJson('/api/v1/reports/orders')->assertOk();
        $this->getJson('/api/v1/receipts/general')->assertOk();
    }

    public function test_delivery_hours_suffixes_and_article_csv(): void
    {
        $this->seedRoles();
        [, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/delivery-hours', [
            'lavage_hour' => 48,
            'express_hour' => 12,
            'repassage_hour' => 6,
        ])->assertCreated();

        $this->postJson('/api/v1/code-suffixes', [
            'title' => 'AK',
            'agency_id' => $agency->id,
        ])->assertCreated();

        $csv = "name,classic_price,express_price,repass_price\nPantalon,1500,2000,800\n";
        $file = UploadedFile::fake()->createWithContent('articles.csv', $csv);

        $this->post('/api/v1/articles/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    public function test_sponsorship_and_roles(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin');
        $sponsor = $this->makeClient($agency, ['sponsor_code' => 'ABC123']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/clients', [
            'fullname' => 'Filleul',
            'phone_number' => '699111222',
            'sponsor_code' => 'ABC123',
        ])->assertCreated()
            ->assertJsonPath('data.referred_by_id', $sponsor->id);

        $this->getJson('/api/v1/roles')->assertOk()
            ->assertJsonFragment(['name' => 'caissier']);
    }

    public function test_superadmin_can_manage_user_and_license(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = Admin::query()->create([
            'fullname' => 'Root',
            'email' => 'root@spark.local',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin);

        $userId = $this->postJson('/api/v1/superadmin/users', [
            'fullname' => 'Nouveau',
            'email' => 'nouveau@test.local',
            'password' => 'password12',
            'pressing_id' => $pressing->id,
            'agency_id' => $agency->id,
            'role' => 'caissier',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/superadmin/users/{$userId}", [
            'role' => 'laveur',
        ])->assertOk();

        $licenseId = $this->postJson('/api/v1/superadmin/licenses', [
            'pressing_id' => $pressing->id,
            'plan' => 'pro',
            'activate' => true,
            'months' => 12,
        ])->assertCreated()
            ->assertJsonPath('data.is_activated', true)
            ->json('data.id');

        $this->assertNotNull($licenseId);
    }

    public function test_ticket_pdf_is_downloadable(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $cashier = $this->makeStaff($agency);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing);

        Sanctum::actingAs($cashier);

        $id = $this->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->json('data.id');

        $this->get("/api/v1/deposits/{$id}/document?format=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
