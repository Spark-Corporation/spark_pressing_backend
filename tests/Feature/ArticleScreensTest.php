<?php

namespace Tests\Feature;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 07 — Articles & tarifs ». */
class ArticleScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_catalog_article_form_and_pos_catalog(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency([], ['name' => 'Cocody Angré']);
        $other = Agency::factory()->create(['pressing_id' => $pressing->id, 'name' => 'Bingerville']);
        $admin = $this->makeStaff($agency, 'admin', ['fullname' => 'Nadia Assi']);
        Sanctum::actingAs($admin);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $categoryId = $this->withHeaders($headers)->postJson('/api/v1/article-categories', ['name' => 'Cérémonie', 'icon' => 'suit'])
            ->assertCreated()->json('data.id');

        $id = $this->withHeaders($headers)->postJson('/api/v1/articles', [
            'name' => 'Costume 2 pièces',
            'category_id' => $categoryId,
            'billing_mode' => 'mixed',
            'classic_price' => 6500,
            'express_price' => 9500,
            'repass_price' => 3500,
            'classic_price_kilo' => 2800,
            'express_price_kilo' => 4100,
            'classic_hours' => 72,
            'express_hours' => 24,
            'accepted_defects' => ['stain', 'torn'],
            'services' => ['collection', 'delivery'],
            'kilo_tiers' => [['min_kg' => 0, 'max_kg' => 3, 'classic' => 2800, 'express' => 4100]],
            'availability' => [
                ['agency_id' => $agency->id, 'available' => true, 'price_adjust_percent' => 5],
                ['agency_id' => $other->id, 'available' => false, 'note' => 'Ouverture prochaine'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'COS-'.str_pad('1', 3, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.billing_mode_label', 'Mixte')
            ->assertJsonPath('data.preview.badge', 'Cérémonie · Mixte')
            ->assertJsonPath('data.availability.active', 1)
            ->assertJsonPath('data.availability.agencies.0.status.label', 'Indisponible')
            ->assertJsonPath('data.checks.1.ok', true)
            ->assertJsonPath('data.recommendations.0.code', 'express_gap')
            ->assertJsonPath('data.last_modification.by', 'Nadia Assi')
            ->json('data.id');

        $this->withHeaders($headers)->getJson('/api/v1/articles?tab=unavailable')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.scope.label', 'Cocody Angré')
            ->assertJsonPath('meta.kpis.active_articles.value', 1)
            ->assertJsonPath('meta.kpis.categories.value', 1);

        $this->withHeaders($headers)->getJson('/api/v1/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.catalog.count', 1)
            ->assertJsonPath('data.catalog.items.0.prices.piece.classic', 6825)
            ->assertJsonPath('data.catalog.items.0.category', 'Cérémonie');

        $this->withHeaders($headers)->getJson('/api/v1/articles/history')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Article créé');
    }
}
