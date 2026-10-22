<?php

namespace App\Support\Reporting;

use App\Models\Agency;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\Pressing;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Cash\CashPresenter;
use App\Support\Deposits\DepositPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Écrans Figma « Rapports et bilans » (vue consolidée) et « Bilan journalier et performance caissiers ».
 */
class ReportsPresenter
{
    public const REPORT_LINKS = [
        ['code' => 'daily', 'label' => 'Bilan journalier', 'detail' => 'Recettes, caisse et écarts', 'endpoint' => '/api/v1/reports/daily'],
        ['code' => 'cashiers', 'label' => 'Performance caissiers', 'detail' => 'Productivité et remises', 'endpoint' => '/api/v1/reports/daily'],
        ['code' => 'unpaid', 'label' => 'Impayés clients', 'detail' => 'Soldes et relances', 'endpoint' => '/api/v1/receipts/unpaid'],
        ['code' => 'loyalty', 'label' => 'Fidélité', 'detail' => 'Points et valeur', 'endpoint' => '/api/v1/clients'],
        ['code' => 'cash_flow', 'label' => 'Flux de caisse', 'detail' => 'Dépôts et retraits', 'endpoint' => '/api/v1/cash/journal'],
        ['code' => 'agencies', 'label' => 'Comparaison agences', 'detail' => 'Croissance et panier', 'endpoint' => '/api/v1/org/agencies/overview'],
    ];

    public function overview(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId, ?int $pressingId): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->subDays($days);
        $prevTo = $from->subSecond();
        $cur = $this->period($from, $to, $agencyId);
        $prev = $this->period($prevFrom, $prevTo, $agencyId);
        $pressing = $pressingId ? Pressing::query()->find($pressingId) : null;

        $elapsed = max(1, (int) $from->diffInDays(min($to, CarbonImmutable::now())) + 1);
        $monthDays = $from->daysInMonth;
        $projection = $from->isSameMonth($to) ? (int) round($cur['revenue'] / $elapsed * $monthDays) : $cur['revenue'];
        $target = (int) ($pressing?->monthly_revenue_target ?? 0);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'compare_from' => $prevFrom->toDateString(), 'compare_to' => $prevTo->toDateString()],
            'agency_id' => $agencyId,
            'kpis' => [
                'revenue' => ['value' => $cur['revenue'], 'delta_percent' => $this->delta($cur['revenue'], $prev['revenue'])],
                'sales' => ['value' => $cur['sales'], 'delta_percent' => $this->delta($cur['sales'], $prev['sales']), 'average_basket' => $cur['basket']],
                'discounts' => ['value' => $cur['discounts'], 'share_of_revenue' => $cur['revenue'] ? round($cur['discounts'] / $cur['revenue'] * 100, 1) : 0],
                'unpaid' => ['value' => $cur['unpaid'], 'delta_percent' => $this->delta($cur['unpaid'], $prev['unpaid'])],
            ],
            'revenue_chart' => [
                'series' => $this->dailySeries($from, $to, $agencyId),
                'totals' => ['current' => $cur['revenue'], 'previous' => $prev['revenue'], 'projection' => $projection],
                'objective' => $target ? ['target' => $target, 'percent' => (int) round($projection / $target * 100)] : null,
            ],
            'payments' => $this->paymentsBreakdown($from, $to, $agencyId),
            'non_sales_movements' => $this->nonSales($from, $to, $agencyId),
            'agencies' => $this->agencies($from, $to, $prevFrom, $prevTo),
            'loyalty' => $this->loyalty($from, $to),
            'reports' => self::REPORT_LINKS,
        ];
    }

    public function daily(CarbonImmutable $day, int $agencyId, ?int $pressingId, CashPresenter $cash): array
    {
        $from = $day->startOfDay();
        $to = $day->endOfDay();
        $pressing = $pressingId ? Pressing::query()->find($pressingId) : null;
        $tx = $this->tx($from, $to, $agencyId);
        $receipts = (int) (clone $tx)->sum('amount');
        $lastWeek = (int) $this->tx($from->subWeek(), $to->subWeek(), $agencyId)->sum('amount');
        $deposits = Deposit::query()->where('agency_id', $agencyId)->whereBetween('deposit_date', [$from, $to]);
        $depositCount = (clone $deposits)->count();
        $settled = (clone $deposits)->where('left_to_pay', 0)->count();
        $openUnpaid = Deposit::query()->where('agency_id', $agencyId)->where('status', true)->where('left_to_pay', '>', 0);
        $session = $cash->session($agencyId, $day);
        $closing = $session ? $cash->closing($session) : null;
        $tolerance = (int) ($pressing?->cash_gap_tolerance ?? 5000);
        $gap = $closing['result']['difference'] ?? 0;
        $sensitive = (int) ($pressing?->sensitive_discount_percent ?? 15);
        $discounted = (clone $deposits)->where('discount', '>', 0);

        return [
            'date' => $day->toDateString(),
            'agency' => Agency::query()->whereKey($agencyId)->value('name'),
            'kpis' => [
                'receipts' => ['value' => $receipts, 'delta_percent' => $this->delta($receipts, $lastWeek), 'compared_to' => $from->subWeek()->toDateString()],
                'settled_sales' => ['value' => $settled, 'share_percent' => $depositCount ? round($settled / $depositCount * 100, 1) : 0],
                'open_unpaid' => ['value' => (int) (clone $openUnpaid)->sum('left_to_pay'), 'deposits' => (clone $openUnpaid)->count()],
                'cash_gap' => ['value' => $gap, 'tolerance' => $tolerance, 'within_tolerance' => abs($gap) <= $tolerance],
            ],
            'receipts_chart' => [
                'total' => $receipts,
                'series' => collect(range(8, 22, 2))->map(fn ($h) => [
                    'hour' => sprintf('%02dh', $h),
                    'amount' => (int) $this->tx($from->setTime($h, 0), $from->setTime($h + 1, 59, 59), $agencyId)->sum('amount'),
                ])->all(),
                'methods' => collect(['mobile_money' => 'Mobile Money', 'cash' => 'Espèces', 'card' => 'Carte', 'other' => 'Virement'])
                    ->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'amount' => (int) (clone $tx)->where('payment_method', $code)->sum('amount')])
                    ->values()->all(),
            ],
            'non_deposit_movements' => CashMovement::query()
                ->with('category:id,name')
                ->where('agency_id', $agencyId)
                ->where('validated', true)
                ->whereBetween('action_date', [$from, $to])
                ->get()
                ->groupBy(fn ($m) => $m->category?->name ?? $m->label)
                ->map(fn ($rows, $label) => ['label' => $label, 'amount' => (int) $rows->sum(fn ($m) => $m->type === 'out' ? -$m->amount : $m->amount)])
                ->values()->all(),
            'reconciliation' => [
                'theoretical' => $closing['result']['theoretical'] ?? $cash->center($agencyId, $day)['theoretical']['amount'],
                'counted' => $closing['result']['counted'] ?? null,
                'difference' => $gap,
                'within_tolerance' => abs($gap) <= $tolerance,
                'comment' => $closing['result']['reason'] ?? null,
            ],
            'settlement' => [
                'settled' => $settled,
                'settled_percent' => $depositCount ? round($settled / $depositCount * 100, 1) : 0,
                'unpaid' => $depositCount - $settled,
                'unpaid_amount' => (int) (clone $deposits)->sum('left_to_pay'),
            ],
            'discounts' => [
                'count' => (clone $discounted)->count(),
                'amount' => (int) (clone $discounted)->sum('discount'),
                'share_of_revenue' => ($t = (int) (clone $deposits)->sum('total')) ? round((int) (clone $discounted)->sum('discount') / $t * 100, 1) : 0,
                'sensitive' => [
                    'threshold_percent' => $sensitive,
                    'count' => (clone $discounted)->where('discount_percent', '>', $sensitive)->count(),
                ],
            ],
            'cashiers' => $this->cashiers($from, $to, $agencyId, $session),
            'exports' => ['csv' => true, 'pdf' => true],
        ];
    }

    private function cashiers(CarbonImmutable $from, CarbonImmutable $to, int $agencyId, ?CashSession $session): array
    {
        $rows = Deposit::query()
            ->where('agency_id', $agencyId)
            ->whereBetween('deposit_date', [$from, $to])
            ->selectRaw('user_id, count(*) as sales, coalesce(sum(total),0) as total, min(deposit_date) as first_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
        $receipts = $this->tx($from, $to, $agencyId)->selectRaw('user_id, coalesce(sum(amount),0) as amount')->groupBy('user_id')->pluck('amount', 'user_id');
        $ids = $rows->keys()->merge($receipts->keys())->filter()->unique();
        $max = max(1, (int) $receipts->max());

        return User::query()->whereIn('id', $ids)->get(['id', 'fullname'])->map(function (User $u) use ($rows, $receipts, $max, $session) {
            $r = $rows->get($u->id);
            $amount = (int) ($receipts[$u->id] ?? 0);

            return [
                'user_id' => $u->id,
                'fullname' => $u->fullname,
                'session' => [
                    'status' => $session?->status === 'closed' ? 'Clôturée' : 'Ouverte',
                    'at' => ($session?->status === 'closed' ? $session->closed_at : ($r?->first_at ? CarbonImmutable::parse($r->first_at) : null))?->toIso8601String(),
                ],
                'receipts' => $amount,
                'sales' => (int) ($r?->sales ?? 0),
                'average_basket' => ($r?->sales ?? 0) ? (int) round($r->total / $r->sales) : 0,
                'productivity_percent' => (int) round($amount / $max * 100),
                'gap' => null,
            ];
        })->sortByDesc('receipts')->values()->all();
    }

    /** @return array{revenue:int,sales:int,basket:int,discounts:int,unpaid:int} */
    private function period(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId): array
    {
        $row = Deposit::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereBetween('deposit_date', [$from, $to])
            ->selectRaw('count(*) as cnt, coalesce(sum(total),0) as total, coalesce(sum(discount),0) as discount, coalesce(sum(left_to_pay),0) as unpaid')
            ->first();

        return [
            'revenue' => (int) $row->total,
            'sales' => (int) $row->cnt,
            'basket' => $row->cnt ? (int) round($row->total / $row->cnt) : 0,
            'discounts' => (int) $row->discount,
            'unpaid' => (int) $row->unpaid,
        ];
    }

    private function dailySeries(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId): array
    {
        $rows = Deposit::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereBetween('deposit_date', [$from, $to])
            ->get(['deposit_date', 'total']);

        $series = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $dayRows = $rows->filter(fn ($r) => $r->deposit_date->isSameDay($d));
            $sales = $dayRows->count();
            $series[] = [
                'date' => $d->toDateString(),
                'revenue' => (int) $dayRows->sum('total'),
                'sales' => $sales,
                'average_basket' => $sales ? (int) round($dayRows->sum('total') / $sales) : 0,
            ];
        }

        return $series;
    }

    private function paymentsBreakdown(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId): array
    {
        $rows = $this->tx($from, $to, $agencyId)->selectRaw('payment_method, coalesce(sum(amount),0) as amount')->groupBy('payment_method')->pluck('amount', 'payment_method');
        $total = (int) $rows->sum();

        return [
            'total' => $total,
            'methods' => $rows->map(fn ($amount, $code) => [
                'code' => $code,
                'label' => DepositPresenter::PAYMENT_METHOD_LABELS[$code] ?? $code,
                'amount' => (int) $amount,
                'percent' => $total ? (int) round($amount / $total * 100) : 0,
            ])->sortByDesc('amount')->values()->all(),
        ];
    }

    private function nonSales(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId): array
    {
        $row = CashMovement::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->where('validated', true)
            ->whereBetween('action_date', [$from, $to])
            ->selectRaw("coalesce(sum(case when type = 'in' then amount else 0 end),0) as cash_in, coalesce(sum(case when type = 'out' then amount else 0 end),0) as cash_out")
            ->first();
        $receipts = (int) $this->tx($from, $to, $agencyId)->sum('amount');

        return [
            'cash_in' => (int) $row->cash_in,
            'cash_out' => (int) $row->cash_out,
            'theoretical_balance' => $receipts + (int) $row->cash_in - (int) $row->cash_out,
        ];
    }

    private function agencies(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $agg = fn ($a, $b) => Deposit::query()->whereBetween('deposit_date', [$a, $b])
            ->selectRaw('agency_id, count(*) as cnt, coalesce(sum(total),0) as total')->groupBy('agency_id')->get()->keyBy('agency_id');
        $cur = $agg($from, $to);
        $prev = $agg($prevFrom, $prevTo);

        return Agency::query()->orderBy('name')->get(['id', 'name'])->map(fn (Agency $a) => [
            'agency_id' => $a->id,
            'name' => $a->name,
            'revenue' => (int) ($cur[$a->id]->total ?? 0),
            'sales' => (int) ($cur[$a->id]->cnt ?? 0),
            'average_basket' => ($cur[$a->id]->cnt ?? 0) ? (int) round($cur[$a->id]->total / $cur[$a->id]->cnt) : 0,
            'delta_percent' => $this->delta((int) ($cur[$a->id]->total ?? 0), (int) ($prev[$a->id]->total ?? 0)),
        ])->sortByDesc('revenue')->values()->all();
    }

    private function loyalty(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $issued = (int) LoyaltyLedger::query()->where('type', 'earn')->whereBetween('created_at', [$from, $to])->sum('points');
        $used = (int) abs(LoyaltyLedger::query()->where('type', 'redeem')->whereBetween('created_at', [$from, $to])->sum('points'));
        $span = $from->diffInDays($to) + 1;
        $previousClients = Deposit::query()->whereBetween('deposit_date', [$from->subDays($span), $from->subSecond()])->distinct()->pluck('client_id');
        $returning = $previousClients->isEmpty() ? 0 : Deposit::query()->whereBetween('deposit_date', [$from, $to])->whereIn('client_id', $previousClients)->distinct()->count('client_id');

        return [
            'active_members' => Client::query()->where('loyalty_points', '>', 0)->whereNull('archived_at')->count(),
            'points_issued' => $issued,
            'points_used' => $used,
            'retention_percent' => $previousClients->isEmpty() ? null : (int) round($returning / $previousClients->count() * 100),
        ];
    }

    private function tx(CarbonImmutable $from, CarbonImmutable $to, ?int $agencyId): Builder
    {
        return Transaction::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereBetween('transaction_date', [$from, $to]);
    }

    private function delta(int $current, int $previous): ?float
    {
        return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
