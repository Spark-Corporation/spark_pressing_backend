<?php

namespace Tests\Feature;

use App\Models\Pressing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 09 — Paramètres ». */
class SettingsScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_hub_categories_journal_and_bound_columns(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['delivery_fee' => 1500]);
        $user = $this->makeStaff($agency, 'admin', ['fullname' => 'Awa N.']);
        Sanctum::actingAs($user);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $this->withHeaders($headers)->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.summary.categories', 16)
            ->assertJsonCount(4, 'data.groups')
            ->assertJsonPath('data.groups.0.cards.0.key', 'organisation')
            ->assertJsonPath('data.groups.0.cards.0.summary', '1 agences actives');

        $this->withHeaders($headers)->getJson('/api/v1/settings/operations')
            ->assertOk()
            ->assertJsonPath('data.values.pricing.delivery_fee', 1500)
            ->assertJsonStructure(['data' => ['context' => ['agencies', 'workflow_preview'], 'journal']]);

        $this->withHeaders($headers)->putJson('/api/v1/settings/operations', [
            'values' => ['pricing' => ['delivery_fee' => 2000, 'unknown' => 1], 'delays' => ['express_hours' => 6]],
        ])->assertOk()
            ->assertJsonPath('data.values.pricing.delivery_fee', 2000)
            ->assertJsonCount(2, 'data.changes');

        $fresh = Pressing::query()->find($pressing->id);
        $this->assertSame(2000, (int) $fresh->delivery_fee);
        $this->assertSame(6, (int) $fresh->hours_express);

        $this->withHeaders($headers)->putJson('/api/v1/settings/security', ['values' => ['lockout' => ['max_attempts' => 'abc']]])
            ->assertStatus(422);

        $this->withHeaders($headers)->getJson('/api/v1/settings/journal')
            ->assertOk()
            ->assertJsonPath('data.0.by', 'Awa N.')
            ->assertJsonPath('data.0.summary', 'delays.express_hours : 24 → 6 · pricing.delivery_fee : 1 500 → 2 000');

        $this->withHeaders($headers)->getJson('/api/v1/settings?group=finance')
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.cards.0.status', 'modified');
    }

    public function test_branding_draft_publish_restore_and_receipt_footer(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        Sanctum::actingAs($this->makeStaff($agency, 'admin'));
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $this->withHeaders($headers)->putJson('/api/v1/settings/branding', ['values' => ['primary_color' => '#112233']])
            ->assertOk()->assertJsonPath('data.has_draft', true)->assertJsonPath('data.unsaved_changes', 1);
        $this->withHeaders($headers)->getJson('/api/v1/settings/branding')
            ->assertJsonPath('data.values.primary_color', '#112233')
            ->assertJsonPath('data.published.primary_color', '#24483F');

        $this->withHeaders($headers)->postJson('/api/v1/settings/branding/publish')
            ->assertOk()->assertJsonPath('data.has_draft', false)->assertJsonCount(1, 'data.versions');
        $this->withHeaders($headers)->postJson('/api/v1/settings/branding/restore')
            ->assertOk()->assertJsonPath('data.values.primary_color', '#24483F');
        $this->withHeaders($headers)->postJson('/api/v1/settings/operations/publish')->assertNotFound();

        $this->withHeaders($headers)->putJson('/api/v1/settings/receipts', ['values' => ['footer_message' => 'Bonne journée !'], 'draft' => false])->assertOk();
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 3000]);
        $id = $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id, 'advanced' => 0, 'lines' => [['article_id' => $article->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->getJson("/api/v1/deposits/{$id}/document")
            ->assertOk()->assertJsonPath('data.document.footer', 'Bonne journée !');
    }

    public function test_devices_and_security_policy(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency();
        $admin = $this->makeStaff($agency, 'admin', ['email' => 'admin@spark.ci']);
        Sanctum::actingAs($admin);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $id = $this->withHeaders($headers)->postJson('/api/v1/settings/devices', [
            'agency_id' => $agency->id, 'code' => 'tablette-bg-02', 'type' => 'tablet', 'pending' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.code', 'TABLETTE-BG-02')->json('data.id');

        $this->withHeaders($headers)->getJson('/api/v1/settings')
            ->assertJsonPath('data.groups.3.cards.1.key', 'security')
            ->assertJsonPath('data.groups.3.cards.1.status', 'action_required');

        $this->withHeaders($headers)->postJson("/api/v1/settings/devices/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'authorized')->assertJsonPath('data.status_label', 'Autorisé');
        $this->withHeaders($headers)->postJson("/api/v1/settings/devices/{$id}/revoke", ['reason' => 'Perdu'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withHeaders($headers)->getJson('/api/v1/settings/devices')
            ->assertOk()->assertJsonPath('meta.summary.revoked', 1);

        $this->withHeaders($headers)->putJson('/api/v1/settings/security', ['values' => ['lockout' => ['max_attempts' => 3], 'sessions' => ['max_hours' => 4]]])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/staff/login', ['login' => 'admin@spark.ci', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'admin@spark.ci', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('meta.attempts_remaining', 0)->assertJsonPath('meta.max_attempts', 3);
        $this->postJson('/api/v1/auth/staff/login', ['login' => 'admin@spark.ci', 'password' => 'password'])->assertStatus(429);
    }
}
