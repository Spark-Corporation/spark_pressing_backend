<?php

namespace App\Support\Reporting;

use App\Models\Agency;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\ExchangeRate;
use App\Models\Pressing;
use App\Models\Transaction;
use App\Support\DateRange;
use App\Support\Deposits\WorkshopBoard;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Metrics
{
    public static function depositTotals(DateRange $range): array
    {
        $row = Deposit::query()
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->selectRaw('count(*) as count,
                coalesce(sum(subtotal),0) as subtotal,
                coalesce(sum(discount),0) as discount,
                coalesce(sum(total),0) as total,
                coalesce(sum(advanced),0) as advanced,
                coalesce(sum(left_to_pay),0) as left_to_pay')
            ->first();

        $articles = (int) Deposit::query()
            ->whereBetween('deposits.deposit_date', [$range->from, $range->to])
            ->join('deposit_units', 'deposit_units.deposit_id', '=', 'deposits.id')
            ->whereNull('deposit_units.deleted_at')
            ->sum('deposit_units.quantity');

        return [
            'count' => (int) ($row->count ?? 0),
            'subtotal' => (int) ($row->subtotal ?? 0),
            'discount' => (int) ($row->discount ?? 0),
            'total' => (int) ($row->total ?? 0),
            'advanced' => (int) ($row->advanced ?? 0),
            'left_to_pay' => (int) ($row->left_to_pay ?? 0),
            'articles' => $articles,
        ];
    }

    public static function cashTotals(DateRange $range): array
    {
        $receipts = (int) Transaction::query()
            ->whereBetween('transaction_date', [$range->from, $range->to])
            ->sum('amount');

        $movements = CashMovement::query()
            ->where('validated', true)
            ->whereBetween('action_date', [$range->from, $range->to])
            ->selectRaw("coalesce(sum(case when type = 'in' then amount else 0 end),0) as cash_in,
                coalesce(sum(case when type = 'out' then amount else 0 end),0) as cash_out")
            ->first();

        $cashIn = (int) ($movements->cash_in ?? 0);
        $cashOut = (int) ($movements->cash_out ?? 0);

        return [
            'currency' => config('spark.currency'),
            'from' => $range->from->toIso8601String(),
            'to' => $range->to->toIso8601String(),
            'receipts_deposits' => $receipts,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'balance' => $receipts + $cashIn - $cashOut,
        ];
    }

    public static function cashiers(DateRange $range): Collection
    {
        return Deposit::query()
            ->leftJoin('users', 'users.id', '=', 'deposits.user_id')
            ->whereBetween('deposits.deposit_date', [$range->from, $range->to])
            ->groupBy('deposits.user_id', 'users.fullname')
            ->orderByDesc(DB::raw('coalesce(sum(deposits.total),0)'))
            ->get([
                'deposits.user_id',
                'users.fullname',
                DB::raw('count(*) as deposits'),
                DB::raw('coalesce(sum(deposits.total),0) as total'),
                DB::raw('coalesce(sum(deposits.advanced),0) as advanced'),
            ])
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'fullname' => $row->fullname,
                'deposits' => (int) $row->deposits,
                'total' => (int) $row->total,
                'advanced' => (int) $row->advanced,
            ]);
    }

    public static function ranking(DateRange $range, int $limit = 20): Collection
    {
        return Deposit::query()
            ->leftJoin('clients', 'clients.id', '=', 'deposits.client_id')
            ->whereBetween('deposits.deposit_date', [$range->from, $range->to])
            ->groupBy('deposits.client_id', 'clients.fullname', 'clients.phone_number')
            ->orderByDesc(DB::raw('coalesce(sum(deposits.total),0)'))
            ->limit($limit)
            ->get([
                'deposits.client_id',
                'clients.fullname',
                'clients.phone_number',
                DB::raw('count(*) as deposits'),
                DB::raw('coalesce(sum(deposits.total),0) as total'),
            ])
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'fullname' => $row->fullname,
                'phone_number' => $row->phone_number,
                'deposits' => (int) $row->deposits,
                'total' => (int) $row->total,
            ]);
    }

    public static function byAgency(DateRange $range, Collection $agencies, ?string $targetCurrency = null, ?int $pressingId = null): Collection
    {
        $ids = $agencies->pluck('id');
        $pressingId = $pressingId ?? $agencies->first()?->pressing_id;
        $target = $targetCurrency
            ? Currency::normalize($targetCurrency)
            : null;

        if (! $target && $pressingId) {
            $target = Pressing::query()->whereKey($pressingId)->value('reporting_currency')
                ?: $agencies->first()?->currency
                ?: config('spark.currency');
            $target = Currency::normalize($target);
        }

        $deposits = Deposit::query()
            ->select('agency_id', DB::raw('count(*) as aggregate'))
            ->whereIn('agency_id', $ids)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->groupBy('agency_id')
            ->pluck('aggregate', 'agency_id');

        $retrieves = Deposit::query()
            ->select('agency_id', DB::raw('count(*) as aggregate'))
            ->whereIn('agency_id', $ids)
            ->whereBetween('retrieved_at', [$range->from, $range->to])
            ->groupBy('agency_id')
            ->pluck('aggregate', 'agency_id');

        $receipts = Transaction::query()
            ->select('agency_id', DB::raw('coalesce(sum(amount),0) as aggregate'))
            ->whereIn('agency_id', $ids)
            ->whereBetween('transaction_date', [$range->from, $range->to])
            ->groupBy('agency_id')
            ->pluck('aggregate', 'agency_id');

        $cash = CashMovement::query()
            ->select(
                'agency_id',
                DB::raw("coalesce(sum(case when type = 'in' then amount else 0 end),0) as cash_in"),
                DB::raw("coalesce(sum(case when type = 'out' then amount else 0 end),0) as cash_out")
            )
            ->whereIn('agency_id', $ids)
            ->where('validated', true)
            ->whereBetween('action_date', [$range->from, $range->to])
            ->groupBy('agency_id')
            ->get()
            ->keyBy('agency_id');

        return $agencies->map(function ($agency) use ($deposits, $retrieves, $receipts, $cash, $target, $pressingId, $range) {
            $in = (int) ($cash[$agency->id]->cash_in ?? 0);
            $out = (int) ($cash[$agency->id]->cash_out ?? 0);
            $receipt = (int) ($receipts[$agency->id] ?? 0);
            $currency = Currency::normalize($agency->currency ?: config('spark.currency'));
            $balance = $receipt + $in - $out;

            $converted = [
                'receipts' => $receipt,
                'cash_in' => $in,
                'cash_out' => $out,
                'balance' => $balance,
                'rate' => 1.0,
                'rate_date' => $range->to->toDateString(),
            ];

            if ($target && $pressingId && $currency !== $target) {
                try {
                    $fxReceipt = ExchangeRate::convert($receipt, $pressingId, $currency, $target, $range->to);
                    $fxIn = ExchangeRate::convert($in, $pressingId, $currency, $target, $range->to);
                    $fxOut = ExchangeRate::convert($out, $pressingId, $currency, $target, $range->to);
                    $converted = [
                        'receipts' => $fxReceipt['amount_minor'],
                        'cash_in' => $fxIn['amount_minor'],
                        'cash_out' => $fxOut['amount_minor'],
                        'balance' => $fxReceipt['amount_minor'] + $fxIn['amount_minor'] - $fxOut['amount_minor'],
                        'rate' => $fxReceipt['rate'],
                        'rate_date' => $fxReceipt['rate_date'],
                    ];
                } catch (ValidationException $e) {
                    $converted['fx_error'] = $e->errors()['exchange_rate'][0] ?? 'Taux manquant';
                }
            }

            return [
                'agency_id' => $agency->id,
                'agency' => $agency->name,
                'currency' => $currency,
                'deposits' => (int) ($deposits[$agency->id] ?? 0),
                'retrieves' => (int) ($retrieves[$agency->id] ?? 0),
                'receipts' => $receipt,
                'cash_in' => $in,
                'cash_out' => $out,
                'balance' => $balance,
                'converted' => $converted + ['target_currency' => $target],
            ];
        });
    }

    public static function dashboardToday(): array
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();

        $row = Deposit::query()
            ->selectRaw(
                'coalesce(sum(case when status = 1 then 1 else 0 end), 0) as open_deposits,
                coalesce(sum(case when deposit_date >= ? and deposit_date <= ? then 1 else 0 end), 0) as created_today,
                coalesce(sum(case when status = 0 and retrieved_at >= ? and retrieved_at <= ? then 1 else 0 end), 0) as retrieved_today,
                coalesce(sum(case when status = 1 and left_to_pay > 0 then 1 else 0 end), 0) as unpaid,
                coalesce(sum(case when status = 1 and retrieve_date is not null and retrieve_date < ? then 1 else 0 end), 0) as overdue',
                [$from, $to, $from, $to, now()]
            )
            ->first();

        $cash = self::cashTotals(new DateRange(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        ));

        return [
            'clients' => Client::query()->count(),
            'deposits_open' => (int) ($row->open_deposits ?? 0),
            'deposits_today' => (int) ($row->created_today ?? 0),
            'retrieves_today' => (int) ($row->retrieved_today ?? 0),
            'unpaid_orders' => (int) ($row->unpaid ?? 0),
            'overdue_retrieves' => (int) ($row->overdue ?? 0),
            'receipts_today' => $cash['receipts_deposits'],
            'cash_in_today' => $cash['cash_in'],
            'cash_out_today' => $cash['cash_out'],
            'cash_balance_today' => $cash['balance'],
        ];
    }

    /** Dashboard enrichi pour le FE (KPIs + séries + pipeline + caisse + aging + activité). */
    public static function dashboardNetwork(Collection $agencies, ?int $pressingId = null): array
    {
        $today = self::dashboardToday();
        $todayRange = new DateRange(CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
        $prevRange = new DateRange(
            CarbonImmutable::now()->subDays(7)->startOfDay(),
            CarbonImmutable::now()->subDays(7)->endOfDay(),
        );
        $weekRange = new DateRange(
            CarbonImmutable::now()->subDays(6)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        );

        $prevCash = self::cashTotals($prevRange);
        $prevDeposits = self::depositTotals($prevRange);
        $piecesToday = self::piecesCount($todayRange);
        $piecesPrev = self::piecesCount($prevRange);

        $revenueToday = (int) $today['receipts_today'];
        $depositsToday = (int) $today['deposits_today'];
        $retrievesToday = (int) $today['retrieves_today'];

        $prevWeekRange = new DateRange($weekRange->from->subDays(7), $weekRange->to->subDays(7));
        $weekByAgency = self::byAgency($weekRange, $agencies, null, $pressingId)->keyBy('agency_id');
        $prevWeekByAgency = self::byAgency($prevWeekRange, $agencies, null, $pressingId)->keyBy('agency_id');
        $weekTotal = max(1, (int) $weekByAgency->sum('receipts'));

        $agencyRows = self::byAgency($todayRange, $agencies, null, $pressingId)->map(function (array $row) use ($prevRange, $weekByAgency, $prevWeekByAgency, $weekTotal) {
            $prev = self::agencyDaySnapshot((int) $row['agency_id'], $prevRange);
            $week = (int) ($weekByAgency[$row['agency_id']]['receipts'] ?? 0);
            $prevWeek = (int) ($prevWeekByAgency[$row['agency_id']]['receipts'] ?? 0);

            return [
                'revenue_week' => $week,
                'revenue_week_delta_pct' => self::pctDelta($week, $prevWeek),
                'revenue_week_share_pct' => round($week / $weekTotal * 100, 1),
                'agency_id' => $row['agency_id'],
                'name' => $row['agency'],
                'currency' => $row['currency'],
                'revenue' => $row['receipts'],
                'revenue_delta_pct' => self::pctDelta($row['receipts'], $prev['receipts']),
                'deposits' => $row['deposits'],
                'retrieves' => $row['retrieves'],
                'unpaid' => $prev['unpaid_open'], // replaced below with live unpaid
                'cash_balance' => $row['balance'],
                'cash_in' => $row['cash_in'],
                'cash_out' => $row['cash_out'],
            ];
        });

        // Impayés ouverts par agence (hors période)
        $unpaidByAgency = Deposit::query()
            ->select('agency_id', DB::raw('count(*) as cnt'), DB::raw('coalesce(sum(left_to_pay),0) as amount'))
            ->where('status', true)
            ->where('left_to_pay', '>', 0)
            ->whereIn('agency_id', $agencies->pluck('id'))
            ->groupBy('agency_id')
            ->get()
            ->keyBy('agency_id');

        $agencyRows = $agencyRows->map(function (array $row) use ($unpaidByAgency) {
            $u = $unpaidByAgency[$row['agency_id']] ?? null;
            $row['unpaid_count'] = (int) ($u->cnt ?? 0);
            $row['unpaid_amount'] = (int) ($u->amount ?? 0);
            unset($row['unpaid']);

            return $row;
        });

        $loyaltyClients = (int) Client::query()->where('loyalty_points', '>', 0)->count();
        $loyaltyBefore = (int) Client::query()->where('loyalty_points', '>', 0)->where('created_at', '<', now()->startOfMonth())->count();
        $readyToNotify = (int) Deposit::query()
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->whereIn('etat', [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED])
            ->count();
        $weekRevenue = (int) self::cashTotals($weekRange)['receipts_deposits'];
        $prevWeekRevenue = (int) self::cashTotals($prevWeekRange)['receipts_deposits'];
        $newClientsMonth = (int) Client::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $cash = self::cashTotals($todayRange);
        $byMethod = self::cashByPaymentMethod($todayRange);

        return [
            'currency' => config('spark.currency'),
            'generated_at' => now()->toIso8601String(),
            'kpis' => [
                'revenue_today' => $revenueToday,
                'revenue_delta_pct' => self::pctDelta($revenueToday, $prevCash['receipts_deposits']),
                'deposits_today' => $depositsToday,
                'deposits_delta_pct' => self::pctDelta($depositsToday, $prevDeposits['count']),
                'pieces_today' => $piecesToday,
                'pieces_delta_pct' => self::pctDelta($piecesToday, $piecesPrev),
                'retrieves_today' => $retrievesToday,
                'retrieve_rate_pct' => $depositsToday > 0
                    ? round(($retrievesToday / $depositsToday) * 100, 1)
                    : 0.0,
                'ready_to_notify' => $readyToNotify,
                'loyalty_clients' => $loyaltyClients,
                'loyalty_clients_delta_pct' => self::pctDelta($loyaltyClients, $loyaltyBefore),
                'new_clients_month' => $newClientsMonth,
                'clients' => (int) $today['clients'],
                'deposits_open' => (int) $today['deposits_open'],
                'unpaid_orders' => (int) $today['unpaid_orders'],
                'overdue_retrieves' => (int) $today['overdue_retrieves'],
            ],
            // Rétrocompatibilité avec l’ancien shape plat
            'clients' => (int) $today['clients'],
            'deposits_open' => (int) $today['deposits_open'],
            'deposits_today' => $depositsToday,
            'retrieves_today' => $retrievesToday,
            'unpaid_orders' => (int) $today['unpaid_orders'],
            'overdue_retrieves' => (int) $today['overdue_retrieves'],
            'receipts_today' => $revenueToday,
            'cash_in_today' => (int) $today['cash_in_today'],
            'cash_out_today' => (int) $today['cash_out_today'],
            'cash_balance_today' => (int) $today['cash_balance_today'],
            'series_7d' => self::revenueSeriesDays(7),
            'agencies' => $agencyRows->values(),
            'workshop_pipeline' => self::workshopPipeline(),
            'cash' => [
                'agency' => $agencies->count() === 1 ? $agencies->first()->name : null,
                'period' => 'today',
                'status' => ['code' => 'open', 'label' => 'Caisse ouverte'],
                'balance' => $cash['balance'],
                'inflows' => $cash['receipts_deposits'] + $cash['cash_in'],
                'outflows' => $cash['cash_out'],
                'cash_in' => $cash['cash_in'],
                'cash_out' => $cash['cash_out'],
                'receipts_deposits' => $cash['receipts_deposits'],
                'by_payment_method' => $byMethod,
            ],
            'unpaid_aging' => self::unpaidAging(),
            'recent_activity' => app(ActivityFeed::class)->latest(15),
            'sync' => [
                'online' => true,
                'last_sync_at' => now()->toIso8601String(),
                'pending_ops' => 0,
                'pending' => [],
            ],
            'week' => [
                'from' => $weekRange->from->toDateString(),
                'to' => $weekRange->to->toDateString(),
                'revenue' => $weekRevenue,
                'revenue_delta_pct' => self::pctDelta($weekRevenue, $prevWeekRevenue),
            ],
        ];
    }

    public static function cashByPaymentMethod(DateRange $range): array
    {
        $rows = Transaction::query()
            ->select('payment_method', DB::raw('coalesce(sum(amount),0) as total'))
            ->whereBetween('transaction_date', [$range->from, $range->to])
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $methods = ['cash', 'mobile_money', 'card', 'wallet', 'other'];
        $out = [];
        foreach ($methods as $method) {
            $out[$method] = (int) ($rows[$method] ?? 0);
        }
        foreach ($rows as $method => $total) {
            if (! array_key_exists((string) $method, $out)) {
                $out[(string) ($method ?: 'other')] = (int) $total;
            }
        }

        return $out;
    }

    public static function unpaidAging(): array
    {
        $now = CarbonImmutable::now();

        $unpaid = fn () => Deposit::query()
            ->where('status', true)
            ->where('left_to_pay', '>', 0);

        $totalAmount = (int) $unpaid()->sum('left_to_pay');
        $totalCount = (int) $unpaid()->count();
        $overdueCount = (int) $unpaid()
            ->whereNotNull('retrieve_date')
            ->where('retrieve_date', '<', $now)
            ->count();

        $bucketQuery = function (callable $dateConstraint) use ($unpaid): array {
            $q = $unpaid()->whereNotNull('deposit_date');
            $dateConstraint($q);
            $row = $q->selectRaw('count(*) as cnt, coalesce(sum(left_to_pay),0) as amount')->first();

            return [
                'count' => (int) ($row->cnt ?? 0),
                'amount' => (int) ($row->amount ?? 0),
            ];
        };

        $dueToday = (int) $unpaid()
            ->whereNotNull('retrieve_date')
            ->whereBetween('retrieve_date', [$now->startOfDay(), $now->endOfDay()])
            ->count();

        return [
            'total_amount' => $totalAmount,
            'total_count' => $totalCount,
            'overdue_count' => $overdueCount,
            'due_today_count' => $dueToday,
            'buckets' => [
                '0_30' => $bucketQuery(fn ($q) => $q->where('deposit_date', '>', $now->subDays(30))),
                '31_89' => $bucketQuery(fn ($q) => $q
                    ->where('deposit_date', '<=', $now->subDays(30))
                    ->where('deposit_date', '>', $now->subDays(89))),
                '90_plus' => $bucketQuery(fn ($q) => $q->where('deposit_date', '<=', $now->subDays(90))),
            ],
        ];
    }

    public static function workshopPipeline(): array
    {
        $open = Deposit::query()->where('status', true)->whereNull('retrieved_at');

        $byEtat = (clone $open)
            ->select('etat', DB::raw('count(*) as deposits'))
            ->groupBy('etat')
            ->pluck('deposits', 'etat');

        $piecesByEtat = Deposit::query()
            ->where('deposits.status', true)
            ->whereNull('deposits.retrieved_at')
            ->join('deposit_units', 'deposit_units.deposit_id', '=', 'deposits.id')
            ->whereNull('deposit_units.deleted_at')
            ->select('deposits.etat', DB::raw('coalesce(sum(deposit_units.quantity),0) as pieces'))
            ->groupBy('deposits.etat')
            ->pluck('pieces', 'etat');

        $columns = [];
        foreach (Deposit::etatColumns() as $etat => $colonne) {
            $columns[] = [
                'etat' => $etat,
                'colonne' => $colonne,
                'label' => Deposit::etatLabel($etat),
                'title' => WorkshopBoard::COLUMN_LABELS[$colonne] ?? $colonne,
                'deposits' => (int) ($byEtat[$etat] ?? 0),
                'pieces' => (int) ($piecesByEtat[$etat] ?? 0),
            ];
        }

        $dueSoon = (clone $open)
            ->whereNotNull('retrieve_date')
            ->whereBetween('retrieve_date', [now(), now()->addHours(6)])
            ->orderBy('retrieve_date')
            ->limit(5)
            ->get(['id', 'code', 'retrieve_date', 'etat']);

        $dueSoonCount = (int) (clone $open)
            ->whereNotNull('retrieve_date')
            ->whereBetween('retrieve_date', [now(), now()->addHours(6)])
            ->count();

        return [
            'due_soon_count' => $dueSoonCount,
            'in_progress_deposits' => (int) (clone $open)->count(),
            'in_progress_pieces' => (int) array_sum(array_map('intval', $piecesByEtat->all())),
            'columns' => $columns,
            'due_soon' => $dueSoon->map(fn (Deposit $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'etat' => $d->etat,
                'etat_label' => Deposit::etatLabel($d->etat),
                'retrieve_date' => $d->retrieve_date?->toIso8601String(),
            ])->values(),
        ];
    }

    public static function revenueSeriesDays(int $days = 7): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i);
            $range = new DateRange($day->startOfDay(), $day->endOfDay());
            $cash = self::cashTotals($range);
            $series[] = [
                'date' => $day->toDateString(),
                'revenue' => $cash['receipts_deposits'],
                'deposits' => self::depositTotals($range)['count'],
            ];
        }

        return $series;
    }

    public static function agenciesOverview(Collection $agencies, ?int $pressingId = null): array
    {
        $today = new DateRange(CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
        $week = new DateRange(CarbonImmutable::now()->subDays(6)->startOfDay(), CarbonImmutable::now()->endOfDay());
        $todayRows = self::byAgency($today, $agencies, null, $pressingId)->keyBy('agency_id');
        $weekRows = self::byAgency($week, $agencies, null, $pressingId)->keyBy('agency_id');

        $unpaid = Deposit::query()
            ->select('agency_id', DB::raw('count(*) as cnt'), DB::raw('coalesce(sum(left_to_pay),0) as amount'))
            ->where('status', true)
            ->where('left_to_pay', '>', 0)
            ->whereIn('agency_id', $agencies->pluck('id'))
            ->groupBy('agency_id')
            ->get()
            ->keyBy('agency_id');

        $pieces = Deposit::query()
            ->whereBetween('deposits.deposit_date', [$today->from, $today->to])
            ->whereIn('deposits.agency_id', $agencies->pluck('id'))
            ->join('deposit_units', 'deposit_units.deposit_id', '=', 'deposits.id')
            ->whereNull('deposit_units.deleted_at')
            ->select('deposits.agency_id', DB::raw('coalesce(sum(deposit_units.quantity),0) as pieces'))
            ->groupBy('deposits.agency_id')
            ->pluck('pieces', 'agency_id');

        return $agencies->map(function (Agency $agency) use ($todayRows, $weekRows, $unpaid, $pieces) {
            $t = $todayRows[$agency->id] ?? null;
            $w = $weekRows[$agency->id] ?? null;
            $u = $unpaid[$agency->id] ?? null;
            $deposits = (int) ($t['deposits'] ?? 0);
            $retrieves = (int) ($t['retrieves'] ?? 0);

            return [
                'id' => $agency->id,
                'name' => $agency->name,
                'address' => $agency->address,
                'code_prefix' => $agency->code_prefix,
                'currency' => $agency->currency,
                'status' => (bool) $agency->status,
                'slug' => str($agency->name)->slug()->toString(),
                'revenue_today' => (int) ($t['receipts'] ?? 0),
                'revenue_week' => (int) ($w['receipts'] ?? 0),
                'deposits_today' => $deposits,
                'retrieves_today' => $retrieves,
                'retrieve_rate_pct' => $deposits > 0 ? round(($retrieves / $deposits) * 100, 1) : 0.0,
                'pieces_today' => (int) ($pieces[$agency->id] ?? 0),
                'cash_balance_today' => (int) ($t['balance'] ?? 0),
                'unpaid_count' => (int) ($u->cnt ?? 0),
                'unpaid_amount' => (int) ($u->amount ?? 0),
            ];
        })->values()->all();
    }

    public static function piecesCount(DateRange $range): int
    {
        return (int) Deposit::query()
            ->whereBetween('deposits.deposit_date', [$range->from, $range->to])
            ->join('deposit_units', 'deposit_units.deposit_id', '=', 'deposits.id')
            ->whereNull('deposit_units.deleted_at')
            ->sum('deposit_units.quantity');
    }

    /** @return array{receipts:int, unpaid_open:int} */
    private static function agencyDaySnapshot(int $agencyId, DateRange $range): array
    {
        $receipts = (int) Transaction::query()
            ->where('agency_id', $agencyId)
            ->whereBetween('transaction_date', [$range->from, $range->to])
            ->sum('amount');

        $unpaid = (int) Deposit::query()
            ->where('agency_id', $agencyId)
            ->where('status', true)
            ->where('left_to_pay', '>', 0)
            ->count();

        return ['receipts' => $receipts, 'unpaid_open' => $unpaid];
    }

    private static function pctDelta(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }
}
