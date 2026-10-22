<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 03 — Clients & fidélité ». */
class ClientScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    private array $headers;

    private function setUpStaff(): array
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(
            ['loyalty_redeem_threshold' => 100, 'loyalty_redeem_value' => 500],
            ['name' => 'Bingerville'],
        );
        $user = $this->makeStaff($agency, 'admin', ['fullname' => 'Fatou']);
        Sanctum::actingAs($user);
        $this->headers = ['X-Pressing-Id' => $agency->pressing_id, 'X-Agency-Id' => $agency->id];

        return [$pressing, $agency];
    }

    public function test_create_profile_with_relation_fields_and_duplicate_check(): void
    {
        [, $agency] = $this->setUpStaff();

        $id = $this->withHeaders($this->headers)->postJson('/api/v1/clients', [
            'last_name' => 'Koné',
            'first_name' => 'Aminata',
            'phone_number' => '0748221603',
            'phone_secondary' => '0102030405',
            'city' => 'Bingerville — Faya',
            'agency_id' => $agency->id,
            'contact_preference' => 'whatsapp',
            'loyalty_tier' => 'or',
            'consent_sms' => true,
            'note' => 'Cliente attentive aux finitions.',
        ])->assertCreated()
            ->assertJsonPath('data.fullname', 'Aminata Koné')
            ->assertJsonPath('data.tier.label', 'Or')
            ->assertJsonPath('data.contact_preference.label', 'WhatsApp')
            ->assertJsonPath('data.account.consents.sms', true)
            ->assertJsonPath('data.notes.items.0.author', 'Fatou')
            ->json('data.id');

        $this->assertStringStartsWith('CLI-', Client::find($id)->code);

        $this->withHeaders($this->headers)->getJson('/api/v1/clients/duplicates?phone=0748221603')
            ->assertOk()
            ->assertJsonPath('data.has_duplicates', true)
            ->assertJsonPath('data.matches.0.agency', 'Bingerville');
    }

    public function test_list_kpis_distribution_and_tier_filter(): void
    {
        [, $agency] = $this->setUpStaff();
        $this->makeClient($agency, ['loyalty_points' => 4100]);
        $this->makeClient($agency, ['loyalty_points' => 200]);
        $this->makeClient($agency, ['loyalty_points' => 50, 'archived_at' => now(), 'status' => false]);

        $this->withHeaders($this->headers)->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonPath('meta.kpis.active_clients.value', 2)
            ->assertJsonPath('meta.kpis.vip_clients.value', 1)
            ->assertJsonPath('meta.loyalty_distribution.tiers.0.count', 1)
            ->assertJsonPath('meta.tier_filters.0.label', 'Tous')
            ->assertJsonStructure(['data' => [['initials', 'frequency_per_month', 'lifetime_value', 'tier', 'crm_status']]]);

        $this->withHeaders($this->headers)->getJson('/api/v1/clients?tier=platine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.crm_status.label', 'VIP');

        $this->withHeaders($this->headers)->getJson('/api/v1/clients?crm_status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_profile_loyalty_conversion_notes_and_archive(): void
    {
        [$pressing, $agency] = $this->setUpStaff();
        $client = $this->makeClient($agency, ['loyalty_points' => 3240]);
        $article = $this->makeArticle($pressing, ['classic_price' => 2000]);

        $this->withHeaders($this->headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'lines' => [['article_id' => $article->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->withHeaders($this->headers)->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.loyalty.value', 16200)
            ->assertJsonPath('data.loyalty.tier.label', 'Or')
            ->assertJsonPath('data.loyalty.next_tier.label', 'Platine')
            ->assertJsonPath('data.loyalty.points_to_next_tier', 760)
            ->assertJsonPath('data.loyalty.progress_percent', 81)
            ->assertJsonPath('data.stats.deposits', 1)
            ->assertJsonPath('data.stats.balance_due', 4000)
            ->assertJsonPath('data.recent_orders.0.status.label', 'En cours')
            ->assertJsonPath('data.timeline.0.title', 'Dépôt enregistré');

        $this->withHeaders($this->headers)->postJson("/api/v1/clients/{$client->id}/loyalty/convert", ['points' => 500])
            ->assertOk()
            ->assertJsonPath('data.amount', 2500)
            ->assertJsonPath('data.loyalty.points', 2740);

        $this->withHeaders($this->headers)->postJson("/api/v1/clients/{$client->id}/notes", ['body' => 'Prévenir avant livraison.'])
            ->assertCreated();

        $this->withHeaders($this->headers)->postJson("/api/v1/clients/{$client->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.crm_status.label', 'Archivé');

        $this->assertSame(1, Deposit::query()->where('client_id', $client->id)->count());
    }
}
