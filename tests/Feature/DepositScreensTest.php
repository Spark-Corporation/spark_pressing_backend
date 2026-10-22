<?php

namespace Tests\Feature;

use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 02 — Dépôts & POS ». */
class DepositScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    private array $headers;

    private function createDeposit(): array
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['express_surcharge_percent' => 100], ['code_prefix' => 'BGV']);
        $user = $this->makeStaff($agency, 'admin', ['fullname' => 'Awa N.']);
        $client = $this->makeClient($agency, ['fullname' => 'Aminata Diarra', 'phone_number' => '0748221903']);
        $article = $this->makeArticle($pressing, ['name' => 'Chemise homme', 'classic_price' => 1500]);

        Sanctum::actingAs($user);
        $this->headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];

        $id = $this->withHeaders($this->headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 2000,
            'payment_method' => 'cash',
            'terminal' => 'POS-01',
            'lines' => [[
                'article_id' => $article->id,
                'quantity' => 2,
                'type_action' => 0,
                'express' => true,
                'color' => 'Blanches',
                'defects' => ['stain'],
                'internal_notes' => 'Tache au col',
            ]],
        ])->assertCreated()->json('data.id');

        return [$id, $client];
    }

    public function test_list_exposes_figma_columns_and_kpis(): void
    {
        [$id] = $this->createDeposit();

        $this->withHeaders($this->headers)->getJson('/api/v1/deposits?period=day&payment_status=partial')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.service.label', 'Express')
            ->assertJsonPath('data.0.quantity_label', '2 pièces')
            ->assertJsonPath('data.0.total', 6000)
            ->assertJsonPath('data.0.payment_status.label', 'Partiellement payé')
            ->assertJsonPath('data.0.workshop_status.label', 'À trier')
            ->assertJsonPath('data.0.sync_status.label', 'À jour')
            ->assertJsonPath('meta.kpis.deposits_today.value', 1)
            ->assertJsonPath('meta.kpis.revenue_today.collected', 2000)
            ->assertJsonPath('meta.kpis.left_to_collect.value', 4000);

        $this->withHeaders($this->headers)->getJson('/api/v1/deposits?payment_status=paid')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($this->headers)->get('/api/v1/deposits/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_detail_and_document_payloads(): void
    {
        [$id] = $this->createDeposit();

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$id}/workshop", ['etat' => 'in_progress'])->assertOk();

        $this->withHeaders($this->headers)->getJson("/api/v1/deposits/{$id}")
            ->assertOk()
            ->assertJsonPath('data.terminal', 'POS-01')
            ->assertJsonPath('data.units.0.defects.0.label', 'Taché')
            ->assertJsonPath('data.units.0.internal_notes', 'Tache au col')
            ->assertJsonPath('data.totals.subtotal', 3000)
            ->assertJsonPath('data.totals.express_supplement', 3000)
            ->assertJsonPath('data.payments.0.payment_method.label', 'Espèces')
            ->assertJsonPath('data.payments.0.received_by', 'Awa N.')
            ->assertJsonPath('data.timeline.0.title', 'Pris en charge')
            ->assertJsonPath('data.audit.0.message', 'État atelier passé à « En lavage »')
            ->assertJsonPath('data.audit.1.message', 'Dépôt créé depuis POS-01')
            ->assertJsonPath('data.client_summary.previous_unpaid_count', 0)
            ->assertJsonPath('data.actions.can_add_payment', true);

        $doc = $this->withHeaders($this->headers)->getJson("/api/v1/deposits/{$id}/document?type=facture")
            ->assertOk()
            ->assertJsonPath('data.document.format', 'a4')
            ->assertJsonPath('data.document.lines.1.designation', 'Majoration Express 24 h')
            ->json('data');

        $this->assertStringStartsWith('FAC-BGV-', $doc['document']['number']);

        $this->get($doc['share_url'])->assertOk();

        $this->withHeaders($this->headers)->postJson("/api/v1/deposits/{$id}/document/send", ['channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp');
    }

    public function test_cancel_is_logged_with_reason(): void
    {
        [$id] = $this->createDeposit();

        $this->withHeaders($this->headers)->deleteJson("/api/v1/deposits/{$id}", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->assertSame('Erreur de saisie', Deposit::withTrashed()->find($id)->cancel_reason);
    }

    public function test_bootstrap_exposes_deposit_options(): void
    {
        $this->createDeposit();

        $this->withHeaders($this->headers)->getJson('/api/v1/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.deposit_options.express.multiplier', 2)
            ->assertJsonPath('data.deposit_options.defects.0.label', 'Taché');
    }
}
