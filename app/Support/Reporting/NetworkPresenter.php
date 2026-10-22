<?php

namespace App\Support\Reporting;

use App\Models\Agency;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\DepositUnit;
use App\Models\LoyaltyLedger;
use App\Models\Pressing;
use App\Models\SyncBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Deposits\WorkshopBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Écrans Figma « 10 — Multi-agences » : vue consolidée du réseau et détail d’une agence.
 * Toutes les requêtes sont faites au niveau du pressing (le scope d’agence est levé par le contrôleur).
 */
class NetworkPresenter
{
    /** Une agence est « en ligne » si un lot de synchronisation ou une opération a eu lieu récemment. */
    private const ONLINE_MINUTES = 30;

    public function overview(int $pressingId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$prevFrom, $prevTo] = $this->previous($from, $to);
        $agencies = Agency::query()->where('pressing_id', $pressingId)->where('status', true)->orderBy('name')->get();
        $cur = $this->perAgency($pressingId, $from, $to);
        $prev = $this->perAgency($pressingId, $prevFrom, $prevTo);
        $sum = fn (Collection $rows, string $key) => (int) $rows->sum($key);
        $revenue = $sum($cur, 'revenue');
        $deposits = $sum($cur, 'deposits');
        $retrieved = $sum($cur, 'retrieved');
        $unpaid = $this->openUnpaid($pressingId);
        $cashNet = $this->cashNet($pressingId, null, $from, $to);
        $cashNetPrev = $this->cashNet($pressingId, null, $prevFrom, $prevTo);
        $objectives = $agencies->mapWithKeys(fn (Agency $a) => [$a->id => $this->objective($a, $pressingId, (int) ($cur[$a->id]['revenue'] ?? 0), $from, $to)]);
        $networkTarget = (int) $objectives->sum('target');
        $sync = $this->syncHealth($pressingId, $agencies);

        $rows = $agencies->map(fn (Agency $a) => [
            'agency_id' => $a->id,
            'name' => $a->name,
            'address' => $a->address,
            'revenue' => (int) ($cur[$a->id]['revenue'] ?? 0),
            'delta_percent' => self::delta((int) ($cur[$a->id]['revenue'] ?? 0), (int) ($prev[$a->id]['revenue'] ?? 0)),
            'share_percent' => $revenue ? round(($cur[$a->id]['revenue'] ?? 0) / $revenue * 100, 1) : 0,
            'deposits' => (int) ($cur[$a->id]['deposits'] ?? 0),
            'retrieved' => (int) ($cur[$a->id]['retrieved'] ?? 0),
            'unpaid' => (int) ($unpaid['by_agency'][$a->id]['amount'] ?? 0),
            'unpaid_level' => $this->unpaidLevel((int) ($unpaid['by_agency'][$a->id]['amount'] ?? 0), $unpaid['amount'], $agencies->count()),
            'objective' => $objectives[$a->id],
            'online' => $sync['agencies'][$a->id]['online'],
        ]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'compare_from' => $prevFrom->toDateString(), 'compare_to' => $prevTo->toDateString()],
            'kpis' => [
                'revenue' => ['value' => $revenue, 'delta_percent' => self::delta($revenue, $sum($prev, 'revenue'))],
                'deposits' => ['value' => $deposits, 'pieces' => $sum($cur, 'pieces')],
                'retrievals' => ['value' => $retrieved, 'finalized_percent' => $deposits ? round($retrieved / $deposits * 100, 1) : 0],
                'cash_flow' => ['value' => $cashNet, 'delta_percent' => self::delta($cashNet, $cashNetPrev)],
                'unpaid' => ['value' => $unpaid['amount'], 'deposits' => $unpaid['count'], 'share_of_revenue' => $revenue ? round($unpaid['amount'] / $revenue * 100, 1) : 0],
                'loyalty' => [
                    'members' => Client::query()->where('pressing_id', $pressingId)->where('loyalty_points', '>', 0)->whereNull('archived_at')->count(),
                    'points_issued' => (int) LoyaltyLedger::query()->where('pressing_id', $pressingId)->where('type', 'earn')->whereBetween('created_at', [$from, $to])->sum('points'),
                ],
                'sync_health' => ['percent' => $sync['availability_percent'], 'online_agencies' => $sync['online'], 'agencies' => $agencies->count()],
                'network_objective' => [
                    'percent' => $networkTarget ? (int) round($revenue / $networkTarget * 100) : null,
                    'target' => $networkTarget ?: null,
                    'agencies_above_85' => $objectives->filter(fn ($o) => ($o['percent'] ?? 0) >= 85)->count(),
                ],
            ],
            'revenue_chart' => ['total' => $revenue, 'series' => $this->dailySeries($pressingId, null, $from, $to)],
            'ranking' => $rows->sortByDesc('revenue')->values()->map(fn ($r, $i) => ['rank' => $i + 1] + collect($r)->only(['agency_id', 'name', 'revenue', 'delta_percent', 'share_percent'])->all())->all(),
            'comparison' => $rows->map(fn ($r) => collect($r)->only(['agency_id', 'name', 'deposits', 'retrieved', 'unpaid', 'unpaid_level', 'objective'])->all())->values()->all(),
            'alerts' => $this->alerts($pressingId, $agencies, $rows, $sync),
            'agencies' => $rows->map(fn ($r) => [
                'agency_id' => $r['agency_id'],
                'name' => $r['name'],
                'address' => $r['address'],
                'online' => $r['online'],
                'status_label' => $r['online'] ? 'En ligne' : 'Hors ligne',
                'deposits' => $r['deposits'],
                'objective_percent' => $r['objective']['percent'],
                'endpoint' => '/api/v1/network/agencies/'.$r['agency_id'],
            ])->values()->all(),
        ];
    }

    public function agency(Agency $agency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pressingId = (int) $agency->pressing_id;
        [$prevFrom, $prevTo] = $this->previous($from, $to);
        $cur = $this->perAgency($pressingId, $from, $to);
        $prev = $this->perAgency($pressingId, $prevFrom, $prevTo);
        $mine = $cur[$agency->id] ?? ['revenue' => 0, 'deposits' => 0, 'retrieved' => 0, 'pieces' => 0];
        $ranking = $cur->sortByDesc('revenue')->keys()->values();
        $count = max(1, Agency::query()->where('pressing_id', $pressingId)->where('status', true)->count());
        $networkRevenue = (int) $cur->sum('revenue');
        $networkDeposits = max(1, (int) $cur->sum('deposits'));
        $avgRevenue = $networkRevenue / $count;
        $basket = $mine['deposits'] ? (int) round($mine['revenue'] / $mine['deposits']) : 0;
        $networkBasket = (int) round($networkRevenue / $networkDeposits);
        $unpaid = $this->openUnpaid($pressingId);
        $myUnpaid = (int) ($unpaid['by_agency'][$agency->id]['amount'] ?? 0);
        $unpaidRate = $mine['revenue'] ? round($myUnpaid / $mine['revenue'] * 100, 1) : 0;
        $networkUnpaidRate = $networkRevenue ? round($unpaid['amount'] / $networkRevenue * 100, 1) : 0;
        $late = $this->lateRate($pressingId, $agency->id);
        $networkLate = $this->lateRate($pressingId, null);
        $session = CashSession::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->latest('id')->first();
        $sync = $this->syncHealth($pressingId, collect([$agency]));

        return [
            'agency' => [
                'id' => $agency->id,
                'name' => $agency->name,
                'code' => $agency->code_prefix ? sprintf('%s-%02d', $agency->code_prefix, $agency->id) : null,
                'address' => $agency->address,
                'contact' => $agency->contact,
                'email' => $agency->email,
                'opening_hours' => $agency->opens_at ? ['opens_at' => $agency->opens_at, 'closes_at' => $agency->closes_at, 'label' => "ouverte aujourd’hui de {$agency->opens_at} à {$agency->closes_at}"] : null,
            ],
            'kpis' => [
                'revenue' => ['value' => $mine['revenue'], 'delta_percent' => self::delta($mine['revenue'], (int) ($prev[$agency->id]['revenue'] ?? 0)), 'network_rank' => $ranking->search($agency->id) === false ? null : $ranking->search($agency->id) + 1],
                'cash_today' => [
                    'value' => $this->cashNet($pressingId, $agency->id, CarbonImmutable::today(), CarbonImmutable::now()),
                    'gap' => $session?->difference,
                    'session_status' => $session ? ($session->closed_at ? 'closed' : 'open') : null,
                ],
                'deposits_retrievals' => ['deposits' => $mine['deposits'], 'retrieved' => $mine['retrieved'], 'retrieval_rate' => $mine['deposits'] ? round($mine['retrieved'] / $mine['deposits'] * 100, 1) : 0],
                'objective' => $this->objective($agency, $pressingId, $mine['revenue'], $from, $to),
            ],
            'performance_chart' => ['total' => $mine['revenue'], 'cumulative' => true, 'series' => $this->cumulative($this->dailySeries($pressingId, $agency->id, $from, $to))],
            'network_comparison' => [
                ['code' => 'revenue', 'label' => 'Chiffre d’affaires', 'value' => $avgRevenue ? round(($mine['revenue'] - $avgRevenue) / $avgRevenue * 100, 1) : null, 'unit' => 'percent', 'detail' => 'vs moyenne réseau'],
                ['code' => 'basket', 'label' => 'Panier moyen', 'value' => $basket, 'unit' => 'amount', 'detail' => sprintf('%+d FCFA vs réseau', $basket - $networkBasket)],
                ['code' => 'unpaid', 'label' => 'Impayés', 'value' => $unpaidRate, 'unit' => 'percent', 'detail' => sprintf('%+.1f pts vs réseau', $unpaidRate - $networkUnpaidRate)],
                ['code' => 'workshop_delays', 'label' => 'Retards atelier', 'value' => $late, 'unit' => 'percent', 'detail' => sprintf('%+.1f pt vs réseau', $late - $networkLate), 'alert' => $late > $networkLate],
                ['code' => 'satisfaction', 'label' => 'Satisfaction', 'value' => null, 'unit' => 'score', 'detail' => 'Avis clients non collectés'],
            ],
            'workshop' => $this->workshop($agency->id),
            'clients' => $this->clients($agency->id),
            'delays_unpaid' => $this->delaysUnpaid($agency->id),
            'team' => $this->team($agency->id),
            'history' => $this->history($agency->id),
            'sync' => $sync['agencies'][$agency->id] + ['availability_percent' => $sync['availability_percent']],
        ];
    }

    /** @return Collection<int, array{revenue:int,deposits:int,retrieved:int,pieces:int}> */
    private function perAgency(int $pressingId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $deposits = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereBetween('deposit_date', [$from, $to])
            ->selectRaw('agency_id, count(*) as cnt, coalesce(sum(total),0) as total')->groupBy('agency_id')->get()->keyBy('agency_id');
        $retrieved = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereBetween('retrieved_at', [$from, $to])
            ->selectRaw('agency_id, count(*) as cnt')->groupBy('agency_id')->pluck('cnt', 'agency_id');
        $pieces = DepositUnit::query()->join('deposits', 'deposits.id', '=', 'deposit_units.deposit_id')
            ->where('deposits.pressing_id', $pressingId)->whereBetween('deposits.deposit_date', [$from, $to])
            ->selectRaw('deposits.agency_id as agency_id, coalesce(sum(deposit_units.quantity),0) as qty')->groupBy('deposits.agency_id')->pluck('qty', 'agency_id');

        return $deposits->keys()->merge($retrieved->keys())->unique()->mapWithKeys(fn ($id) => [$id => [
            'revenue' => (int) ($deposits[$id]->total ?? 0),
            'deposits' => (int) ($deposits[$id]->cnt ?? 0),
            'retrieved' => (int) ($retrieved[$id] ?? 0),
            'pieces' => (int) ($pieces[$id] ?? 0),
        ]]);
    }

    private function openUnpaid(int $pressingId): array
    {
        $rows = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('status', true)->where('left_to_pay', '>', 0)
            ->selectRaw('agency_id, count(*) as cnt, coalesce(sum(left_to_pay),0) as amount')->groupBy('agency_id')->get()->keyBy('agency_id');

        return [
            'amount' => (int) $rows->sum('amount'),
            'count' => (int) $rows->sum('cnt'),
            'by_agency' => $rows->map(fn ($r) => ['amount' => (int) $r->amount, 'count' => (int) $r->cnt])->all(),
        ];
    }

    private function unpaidLevel(int $amount, int $total, int $agencies): string
    {
        $average = $agencies ? $total / $agencies : 0;

        return $average && $amount > $average * 1.5 ? 'high' : 'normal';
    }

    private function cashNet(int $pressingId, ?int $agencyId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $receipts = (int) Transaction::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $row = CashMovement::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))->where('validated', true)->whereBetween('action_date', [$from, $to])
            ->selectRaw("coalesce(sum(case when type = 'in' then amount else 0 end),0) as cin, coalesce(sum(case when type = 'out' then amount else 0 end),0) as cout")->first();

        return $receipts + (int) $row->cin - (int) $row->cout;
    }

    /** Objectif mensuel : celui de l’agence, sinon l’objectif du pressing réparti entre agences actives. */
    private function objective(Agency $agency, int $pressingId, int $revenue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $target = (int) $agency->monthly_revenue_target;
        if (! $target) {
            $network = (int) Pressing::query()->whereKey($pressingId)->value('monthly_revenue_target');
            $count = Agency::query()->where('pressing_id', $pressingId)->where('status', true)->count();
            $target = $network && $count ? (int) round($network / $count) : 0;
        }
        $months = max(1, $from->diffInMonths($to->addDay()));
        $target *= $months;

        return [
            'target' => $target ?: null,
            'percent' => $target ? (int) round($revenue / $target * 100) : null,
            'remaining' => $target ? max(0, $target - $revenue) : null,
            'level' => ! $target ? null : match (true) {
                $revenue / $target >= 0.85 => 'good', $revenue / $target >= 0.7 => 'warning', default => 'critical'
            },
        ];
    }

    private function dailySeries(int $pressingId, ?int $agencyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->whereBetween('deposit_date', [$from, $to])->get(['deposit_date', 'total'])
            ->groupBy(fn ($d) => $d->deposit_date->toDateString());
        $series = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'revenue' => (int) ($rows->get($d->toDateString())?->sum('total') ?? 0)];
        }

        return $series;
    }

    private function cumulative(array $series): array
    {
        $running = 0;

        return array_map(function ($p) use (&$running) {
            $running += $p['revenue'];

            return $p + ['cumulative' => $running];
        }, $series);
    }

    /** Disponibilité 30 jours (lots réussis / lots envoyés) et agences en ligne. */
    private function syncHealth(int $pressingId, Collection $agencies): array
    {
        $since = now()->subDays(30);
        $batches = SyncBatch::query()->where('pressing_id', $pressingId)->where('created_at', '>=', $since)->get(['agency_id', 'status', 'operations', 'failed', 'created_at']);
        $lastDeposit = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->selectRaw('agency_id, max(updated_at) as at')->groupBy('agency_id')->pluck('at', 'agency_id');
        $perAgency = $agencies->mapWithKeys(function (Agency $a) use ($batches, $lastDeposit) {
            $mine = $batches->where('agency_id', $a->id);
            $lastBatch = $mine->sortByDesc('created_at')->first();
            $lastActivity = collect([$lastBatch?->created_at, $lastDeposit[$a->id] ?? null])->filter()->map(fn ($d) => CarbonImmutable::parse($d))->max();

            return [$a->id => [
                'online' => $lastActivity !== null && $lastActivity->gt(now()->subMinutes(self::ONLINE_MINUTES)),
                'status_label' => $lastActivity && $lastActivity->gt(now()->subMinutes(self::ONLINE_MINUTES)) ? 'En ligne · synchronisée' : 'Hors ligne',
                'last_sync_at' => $lastActivity?->toIso8601String(),
                'pending_operations' => (int) $mine->where('status', '!=', 'completed')->sum('failed'),
                'interruptions_30d' => $mine->where('status', 'failed')->count(),
                'last_batch_status' => $lastBatch?->status,
            ]];
        });
        $total = $batches->count();

        return [
            'availability_percent' => $total ? round($batches->where('status', '!=', 'failed')->count() / $total * 100, 1) : 100.0,
            'online' => $perAgency->where('online', true)->count(),
            'agencies' => $perAgency->all(),
        ];
    }

    /** Retards : dépôts ouverts non prêts dont la date de retrait est dépassée (en % des dépôts ouverts). */
    private function lateRate(int $pressingId, ?int $agencyId): float
    {
        $open = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('status', true)->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId));
        $total = (clone $open)->count();
        $late = (clone $open)->whereIn('etat', [Deposit::ETAT_WAITING, Deposit::ETAT_IN_PROGRESS])->where('retrieve_date', '<', now())->count();

        return $total ? round($late / $total * 100, 1) : 0.0;
    }

    private function alerts(int $pressingId, Collection $agencies, Collection $rows, array $sync): array
    {
        $alerts = [];
        foreach ($rows as $r) {
            if ($r['unpaid_level'] === 'high') {
                $alerts[] = ['agency_id' => $r['agency_id'], 'type' => 'unpaid', 'severity' => 'critical', 'title' => "{$r['name']} · impayés élevés",
                    'detail' => number_format($r['unpaid'], 0, ',', ' ').' FCFA', 'endpoint' => '/api/v1/receipts/unpaid?agency_id='.$r['agency_id']];
            }
            $open = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('agency_id', $r['agency_id'])->where('status', true);
            $late = (clone $open)->whereIn('etat', [Deposit::ETAT_WAITING, Deposit::ETAT_IN_PROGRESS])->where('retrieve_date', '<', now())->count();
            if ($late > 0) {
                $today = (clone $open)->whereDate('retrieve_date', today())->count();
                $alerts[] = ['agency_id' => $r['agency_id'], 'type' => 'delays', 'severity' => 'warning', 'title' => "{$r['name']} · {$late} retards",
                    'detail' => "{$today} retraits promis aujourd’hui", 'endpoint' => '/api/v1/workshop/board?agency_id='.$r['agency_id']];
            }
            $pending = $sync['agencies'][$r['agency_id']]['pending_operations'] ?? 0;
            if ($pending > 0) {
                $alerts[] = ['agency_id' => $r['agency_id'], 'type' => 'sync', 'severity' => 'warning', 'title' => "{$r['name']} · {$pending} opérations hors ligne",
                    'detail' => 'Dernier envoi '.(CarbonImmutable::parse($sync['agencies'][$r['agency_id']]['last_sync_at'])->locale('fr')->diffForHumans()), 'endpoint' => '/api/v1/sync/center?agency_id='.$r['agency_id']];
            }
            $pct = $r['objective']['percent'];
            if ($pct !== null && $pct >= 85 && $pct < 100) {
                $alerts[] = ['agency_id' => $r['agency_id'], 'type' => 'objective', 'severity' => 'info', 'title' => "{$r['name']} · objectif proche",
                    'detail' => "{$pct} % atteint", 'endpoint' => '/api/v1/network/agencies/'.$r['agency_id']];
            }
        }
        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $alerts;
    }

    private function workshop(int $agencyId): array
    {
        $rows = Deposit::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('status', true)
            ->withSum('units as pieces', 'quantity')->get(['id', 'etat', 'retrieve_date']);
        $columns = collect(Deposit::etatColumns())->map(fn ($column, $etat) => [
            'etat' => $etat,
            'label' => WorkshopBoard::COLUMN_LABELS[$column],
            'deposits' => $rows->where('etat', $etat)->count(),
            'pieces' => (int) $rows->where('etat', $etat)->sum('pieces'),
        ])->values();
        $active = $rows->whereIn('etat', [Deposit::ETAT_WAITING, Deposit::ETAT_IN_PROGRESS]);

        return [
            'orders' => $rows->count(),
            'pieces' => (int) $rows->sum('pieces'),
            'columns' => $columns->all(),
            'due_soon' => $active->filter(fn ($d) => $d->retrieve_date && $d->retrieve_date->between(now(), now()->addHours(6)))->count(),
            'late' => $active->filter(fn ($d) => $d->retrieve_date && $d->retrieve_date->isPast())->count(),
        ];
    }

    private function clients(int $agencyId): array
    {
        $clients = Client::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('archived_at');
        $active = (clone $clients)->count();
        $members = (clone $clients)->where('loyalty_points', '>', 0)->count();
        $vip = config('spark.loyalty_tiers.'.config('spark.vip_tier').'.min_points', PHP_INT_MAX);
        $distribution = collect(config('spark.loyalty_tiers'))->map(fn ($tier, $code) => ['code' => $code, 'label' => $tier['label']]);

        return [
            'active' => $active,
            'new_this_month' => (clone $clients)->where('created_at', '>=', now()->startOfMonth())->count(),
            'loyalty_members' => $members,
            'members_percent' => $active ? (int) round($members / $active * 100) : 0,
            'points_this_month' => (int) LoyaltyLedger::query()->withoutGlobalScopes()->where('type', 'earn')->where('created_at', '>=', now()->startOfMonth())
                ->whereIn('client_id', (clone $clients)->select('id'))->sum('points'),
            'new_vip_7d' => (clone $clients)->where('loyalty_points', '>=', $vip)->where('updated_at', '>=', now()->subDays(7))->count(),
            'distribution' => $distribution->map(fn ($t) => $t + ['count' => (clone $clients)->get(['id', 'loyalty_points', 'loyalty_tier'])->filter(fn (Client $c) => $c->tier() === $t['code'])->count()])->values()->all(),
        ];
    }

    private function delaysUnpaid(int $agencyId): array
    {
        $open = Deposit::query()->withoutGlobalScopes()->with('client:id,fullname')->where('agency_id', $agencyId)->where('status', true);
        $late = (clone $open)->whereIn('etat', [Deposit::ETAT_WAITING, Deposit::ETAT_IN_PROGRESS])->where('retrieve_date', '<', now())->orderBy('retrieve_date')->get();
        $unpaid = (clone $open)->where('left_to_pay', '>', 0)->get();
        $old = $unpaid->filter(fn ($d) => $d->deposit_date && $d->deposit_date->lt(now()->subDays(30)));
        $toRecover = $late->merge($unpaid)->unique('id');

        return [
            'to_recover' => ['amount' => (int) $toRecover->sum('left_to_pay'), 'deposits' => $toRecover->count()],
            'workshop_delays' => ['deposits' => $late->count(), 'over_24h' => $late->filter(fn ($d) => $d->retrieve_date->lt(now()->subDay()))->count()],
            'unpaid_followup' => ['clients' => $old->pluck('client_id')->unique()->count(), 'amount' => (int) $old->sum('left_to_pay'), 'since_days' => 30],
            'rows' => $toRecover->take(5)->map(fn (Deposit $d) => [
                'deposit_id' => $d->id,
                'code' => $d->code,
                'client' => $d->client?->fullname,
                'delay' => $d->retrieve_date && $d->retrieve_date->isPast() ? self::duration($d->retrieve_date) : null,
                'left_to_pay' => (int) $d->left_to_pay,
            ])->values()->all(),
        ];
    }

    private function team(int $agencyId): array
    {
        $users = User::query()->where('agency_id', $agencyId)->where('status', true)->with('roles:id,name')->get(['id', 'fullname']);
        $active = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users->pluck('id'))
            ->where('last_used_at', '>=', now()->startOfDay())->pluck('tokenable_id')->unique();
        $members = $users->map(fn (User $u) => [
            'user_id' => $u->id,
            'fullname' => $u->fullname,
            'initials' => collect(explode(' ', (string) $u->fullname))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''),
            'role' => $u->roles->first()?->name,
            'present' => $active->contains($u->id),
            'status_label' => $active->contains($u->id) ? 'Présent' : 'Absent',
        ]);

        return ['members' => $members->count(), 'present' => $members->where('present', true)->count(), 'list' => $members->values()->all()];
    }

    private function history(int $agencyId): array
    {
        return collect(app(ActivityFeed::class)->latest(6, $agencyId))
            ->map(fn ($item) => collect($item)->only(['at', 'type', 'title', 'subtitle', 'amount', 'amount_type'])->all())
            ->all();
    }

    private function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subSecond()];
    }

    private static function duration(CarbonImmutable|\Carbon\CarbonInterface $since): string
    {
        $minutes = (int) $since->diffInMinutes(now());

        return match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => intdiv($minutes, 60).' h '.($minutes % 60).' min',
            default => intdiv($minutes, 1440).' j '.intdiv($minutes % 1440, 60).' h',
        };
    }

    private static function delta(int $current, int $previous): ?float
    {
        return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
