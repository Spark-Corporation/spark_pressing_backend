<?php

namespace Tests\Feature;

use App\Models\CashMovementCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 06 — Caisse ». */
class CashScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_cash_center_double_control_and_closing(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['cash_double_control_threshold' => 250000], ['code_prefix' => 'BG']);
        $cashier = $this->makeStaff($agency, 'caissier', ['fullname' => 'Grâce A.']);
        $manager = $this->makeStaff($agency, 'manager', ['fullname' => 'Aïcha K.']);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 10000]);
        $category = CashMovementCategory::query()->create(['pressing_id' => $pressing->id, 'name' => 'Fournitures', 'direction' => 'out', 'status' => true]);
        $headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];

        Sanctum::actingAs($cashier);
        $sessionId = $this->withHeaders($headers)->postJson('/api/v1/cash/sessions', ['opening_balance' => 400000])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CAI-BG-'.now()->format('md'))
            ->json('data.id');

        $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 6000,
            'payment_method' => 'mobile_money',
            'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated();

        // Sortie au-delà du seuil : catégorie et justificatif obligatoires, puis double contrôle.
        $this->withHeaders($headers)->postJson('/api/v1/cash-movements', [
            'label' => 'Achat urgent de produits', 'type' => 'out', 'amount' => 300000,
        ])->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'category_id')
            ->assertJsonPath('errors.1.field', 'proof');

        $mvt = $this->withHeaders($headers)->post('/api/v1/cash-movements', [
            'label' => 'Achat urgent de produits',
            'type' => 'out',
            'amount' => 300000,
            'category_id' => $category->id,
            'beneficiary' => 'CleanPro Côte d’Ivoire',
            'reference' => 'BC-2026-0928-18',
            'proof' => UploadedFile::fake()->create('facture.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status.code', 'pending_approval')
            ->assertJsonPath('data.double_control.approver.state', 'À notifier')
            ->assertJsonPath('data.traceability.1.title', 'Montant sensible détecté')
            ->json('data.id');

        $this->withHeaders($headers)->postJson("/api/v1/cash-movements/{$mvt}/validate")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->withHeaders($headers)->postJson("/api/v1/cash-movements/{$mvt}/validate")
            ->assertOk()
            ->assertJsonPath('data.status.label', 'Validé')
            ->assertJsonPath('data.double_control.approver.fullname', 'Aïcha K.');

        $this->withHeaders($headers)->getJson('/api/v1/cash/center')
            ->assertOk()
            ->assertJsonPath('data.session.status.label', 'Caisse ouverte')
            ->assertJsonPath('data.situation.opening.amount', 400000)
            ->assertJsonPath('data.situation.deposit_receipts.amount', 6000)
            ->assertJsonPath('data.situation.cash_out.amount', 300000)
            ->assertJsonPath('data.theoretical.amount', 106000)
            ->assertJsonPath('data.breakdown.methods.1.amount', 6000)
            ->assertJsonCount(12, 'data.hourly.series');

        $this->withHeaders($headers)->getJson('/api/v1/cash/journal?flow=out')
            ->assertOk()
            ->assertJsonPath('data.0.reference', sprintf('MVT-%05d', $mvt))
            ->assertJsonPath('data.0.category', 'Fournitures');

        // Comptage par le caissier, contrôle par le manager, clôture.
        Sanctum::actingAs($cashier);
        $this->withHeaders($headers)->postJson("/api/v1/cash/sessions/{$sessionId}/count", [
            'counted' => ['cash' => 100000, 'mobile_money' => 6000, 'card' => 0],
        ])->assertOk()
            ->assertJsonPath('data.result.label', 'Caisse conforme · écart zéro')
            ->assertJsonPath('data.reconciliation.0.status.label', 'Conforme');

        $this->withHeaders($headers)->postJson("/api/v1/cash/sessions/{$sessionId}/close")->assertStatus(422);

        Sanctum::actingAs($manager);
        $this->withHeaders($headers)->postJson("/api/v1/cash/sessions/{$sessionId}/control")
            ->assertOk()
            ->assertJsonPath('data.status.label', 'Prête à clôturer');

        $this->withHeaders($headers)->postJson("/api/v1/cash/sessions/{$sessionId}/close")
            ->assertOk()
            ->assertJsonPath('data.status.label', 'Clôturée')
            ->assertJsonPath('data.checklist.done', 6);

        $this->withHeaders($headers)->get("/api/v1/cash/sessions/{$sessionId}/report")->assertOk();
    }
}
