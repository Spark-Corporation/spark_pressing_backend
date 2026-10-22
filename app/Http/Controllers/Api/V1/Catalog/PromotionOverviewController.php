<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\Promo;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class PromotionOverviewController extends Controller
{
    /**
     * « Promotions et fidélité » : KPIs du mois, règles et niveaux du programme, historique des points,
     * groupes automatiques, tableau des campagnes (`agency_id`, `status`).
     */
    public function overview(Request $request, SettingsRegistry $settings): JsonResponse
    {
        $data = $request->validate(['agency_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:active,scheduled,exhausted,ended,disabled']]);
        $pressingId = $request->user()->pressing_id;
        $loyalty = $settings->get($pressingId, 'loyalty');
        $monthStart = now()->startOfMonth();
        $prevStart = now()->subMonthNoOverflow()->startOfMonth();

        $clients = Client::query()->where('pressing_id', $pressingId);
        $members = (clone $clients)->where('loyalty_points', '>', 0)->count();
        $membersPrev = (clone $clients)->where('loyalty_points', '>', 0)->where('created_at', '<', $monthStart)->count();
        $ledger = LoyaltyLedger::query()->where('pressing_id', $pressingId);
        $issued = (int) (clone $ledger)->where('type', 'earn')->sum('points');
        $consumed = (int) abs((clone $ledger)->where('type', 'redeem')->sum('points'));
        $outstanding = (int) (clone $clients)->sum('loyalty_points');
        $minimum = (int) $loyalty['program']['minimum_points'];
        $consumable = (int) (clone $clients)->where('loyalty_points', '>=', $minimum)->sum('loyalty_points');
        $discounts = (int) Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('created_at', '>=', $monthStart)->sum('discount');

        $promos = Promo::query()->latest()->get();
        $active = $promos->filter(fn (Promo $p) => $p->displayStatus() === 'active');
        $agencies = Agency::query()->where('pressing_id', $pressingId)->pluck('name', 'id');
        $rows = $promos
            ->filter(fn (Promo $p) => ! isset($data['agency_id']) || ! $p->agency_ids || in_array((int) $data['agency_id'], array_map('intval', $p->agency_ids), true))
            ->filter(fn (Promo $p) => ! isset($data['status']) || $p->displayStatus() === $data['status'])
            ->map(fn (Promo $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'type' => $p->type,
                'discount_label' => $p->type === 'amount' ? number_format((int) $p->amount, 0, ',', ' ').' FCFA' : $p->rate_percent.' %',
                'starts_at' => $p->starts_at?->toDateString(),
                'ends_at' => $p->ends_at?->toDateString(),
                'quota' => $p->quota,
                'used' => (int) $p->used,
                'usage_percent' => $p->quota ? (int) round($p->used / $p->quota * 100) : null,
                'per_client_limit' => $p->per_client_limit,
                'agencies' => $p->agency_ids ? collect($p->agency_ids)->map(fn ($id) => $agencies[$id] ?? null)->filter()->values()->all() : ['Toutes les agences'],
                'min_amount' => (int) $p->min_amount,
                'max_discount' => $p->max_discount,
                'stack_with_loyalty' => (bool) $p->stack_with_loyalty,
                'status' => $p->displayStatus(),
                'status_label' => Promo::STATUS_LABELS[$p->displayStatus()],
            ])->values();

        $history = LoyaltyLedger::query()->where('pressing_id', $pressingId)->with('client:id,fullname')->latest('id')->limit(10)->get()
            ->map(fn (LoyaltyLedger $l) => [
                'client' => $l->client?->fullname,
                'reason' => $l->reason,
                'points' => $l->type === 'redeem' ? -abs((int) $l->points) : (int) $l->points,
                'at' => $l->created_at?->toIso8601String(),
            ]);

        $tierCodes = collect(config('spark.loyalty_tiers'));
        $topMin = (int) ($tierCodes['or']['min_points'] ?? PHP_INT_MAX);

        return $this->ok([
            'kpis' => [
                'active_members' => ['value' => $members, 'delta_percent' => $membersPrev ? round(($members - $membersPrev) / $membersPrev * 100, 1) : null],
                'points_issued' => ['value' => $issued, 'consumable_percent' => $outstanding ? (int) round($consumable / $outstanding * 100) : 0],
                'points_consumed' => ['value' => $consumed, 'share_of_stock' => $issued ? round($consumed / $issued * 100, 1) : 0],
                'discounts_granted' => ['value' => $discounts, 'period_from' => $monthStart->toDateString()],
                'active_campaigns' => ['value' => $active->count(), 'uses' => (int) $active->sum('used')],
            ],
            'program' => $loyalty['program'] + [
                'point_value' => $loyalty['program']['conversion_points'] ? round($loyalty['program']['conversion_value'] / $loyalty['program']['conversion_points'], 2) : 0,
                'earn_label' => sprintf('%d points / 1 000 FCFA', $loyalty['program']['earn_points_per_1000']),
                'conversion_label' => sprintf('%s points = %s FCFA', number_format($loyalty['program']['conversion_points'], 0, ',', ' '), number_format($loyalty['program']['conversion_value'], 0, ',', ' ')),
            ],
            'tiers' => $loyalty['tiers'],
            'history' => $history,
            'auto_groups' => [
                ['code' => 'gold_platinum', 'label' => 'Clients Or & Platine', 'count' => (clone $clients)->where('loyalty_points', '>=', $topMin)->count()],
                ['code' => 'reactivate_90', 'label' => 'À réactiver · 90 jours', 'count' => (clone $clients)->whereDoesntHave('deposits', fn ($q) => $q->where('created_at', '>=', now()->subDays(90)))->count()],
                ['code' => 'near_reward', 'label' => 'Proches d’une récompense', 'count' => (clone $clients)->whereBetween('loyalty_points', [(int) floor($minimum * 0.8), max(0, $minimum - 1)])->count()],
                ['code' => 'new_members', 'label' => 'Nouveaux membres', 'count' => (clone $clients)->where('created_at', '>=', now()->subDays(30))->count()],
            ],
            'promotions' => $rows,
        ], ['count' => $rows->count(), 'previous_period_from' => $prevStart->toDateString()]);
    }

    /** Disponibilité d’un code (« Code disponible »). */
    public function checkCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $exists = Promo::query()->where('code', strtoupper($data['code']))->orWhere('code', $data['code'])->exists();

        return $this->ok(['code' => strtoupper($data['code']), 'available' => ! $exists]);
    }
}
