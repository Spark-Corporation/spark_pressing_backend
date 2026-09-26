<?php

namespace App\Support\Reporting;

use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public static function byAgency(DateRange $range, Collection $agencies): Collection
    {
        $ids = $agencies->pluck('id');

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

        return $agencies->map(function ($agency) use ($deposits, $retrieves, $receipts, $cash) {
            $in = (int) ($cash[$agency->id]->cash_in ?? 0);
            $out = (int) ($cash[$agency->id]->cash_out ?? 0);
            $receipt = (int) ($receipts[$agency->id] ?? 0);

            return [
                'agency_id' => $agency->id,
                'agency' => $agency->name,
                'deposits' => (int) ($deposits[$agency->id] ?? 0),
                'retrieves' => (int) ($retrieves[$agency->id] ?? 0),
                'receipts' => $receipt,
                'cash_in' => $in,
                'cash_out' => $out,
                'balance' => $receipt + $in - $out,
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
}
