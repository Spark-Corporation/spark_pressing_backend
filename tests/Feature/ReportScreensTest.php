<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSparkFixtures;
use Tests\TestCase;

/** Écrans Figma « 08 — Rapports & bilans ». */
class ReportScreensTest extends TestCase
{
    use CreatesSparkFixtures, RefreshDatabase;

    public function test_overview_daily_exports_and_schedule(): void
    {
        $this->seedRoles();
        [$pressing, $agency] = $this->makePressingWithAgency(['monthly_revenue_target' => 100000, 'loyalty_points_rate' => 10]);
        $user = $this->makeStaff($agency, 'admin', ['fullname' => 'Nadia Assi']);
        $client = $this->makeClient($agency);
        $article = $this->makeArticle($pressing, ['classic_price' => 10000]);
        Sanctum::actingAs($user);
        $headers = ['X-Pressing-Id' => $pressing->id, 'X-Agency-Id' => $agency->id];

        $this->withHeaders($headers)->postJson('/api/v1/deposits', [
            'client_id' => $client->id,
            'advanced' => 10000,
            'payment_method' => 'mobile_money',
            'discount' => 1000,
            'lines' => [['article_id' => $article->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->withHeaders($headers)->getJson('/api/v1/reports/overview?preset=30d')
            ->assertOk()
            ->assertJsonPath('data.kpis.revenue.value', 19000)
            ->assertJsonPath('data.kpis.sales.average_basket', 19000)
            ->assertJsonPath('data.kpis.discounts.value', 1000)
            ->assertJsonPath('data.payments.methods.0.label', 'Mobile Money')
            ->assertJsonPath('data.agencies.0.revenue', 19000)
            ->assertJsonPath('data.loyalty.points_issued', 100)
            ->assertJsonCount(30, 'data.revenue_chart.series')
            ->assertJsonStructure(['data' => ['revenue_chart' => ['totals' => ['projection']], 'non_sales_movements', 'reports']]);

        $this->withHeaders($headers)->getJson('/api/v1/reports/daily')
            ->assertOk()
            ->assertJsonPath('data.kpis.receipts.value', 10000)
            ->assertJsonPath('data.kpis.open_unpaid.value', 9000)
            ->assertJsonPath('data.cashiers.0.fullname', 'Nadia Assi')
            ->assertJsonPath('data.cashiers.0.productivity_percent', 100)
            ->assertJsonPath('data.discounts.count', 1);

        $this->withHeaders($headers)->get('/api/v1/reports/overview?format=csv')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->withHeaders($headers)->postJson('/api/v1/reports/schedules', [
            'report' => 'overview', 'frequency' => 'weekly', 'recipients' => ['direction@spark.ci'],
        ])->assertCreated()->assertJsonPath('data.frequency', 'weekly');

        Mail::fake();
        \App\Models\ReportSchedule::query()->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('reports:send-scheduled')->assertSuccessful();
        $this->assertNotNull(\App\Models\ReportSchedule::query()->first()->last_sent_at);
    }
}
