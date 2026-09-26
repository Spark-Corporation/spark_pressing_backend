<?php

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\Agency;
use App\Models\Deposit;
use App\Support\DateRange;
use App\Support\Reporting\Metrics;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Rapports')]
class ReportController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return $this->ok($range->toArray() + Metrics::depositTotals($range));
    }

    public function cashiers(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return $this->ok(Metrics::cashiers($range)->values(), $range->toArray());
    }

    public function clients(Request $request): JsonResponse
    {
        $clientId = $request->integer('client_id');
        abort_unless($clientId, 422, 'client_id requis');

        $range = DateRange::fromRequest($request, defaultToday: false);

        $deposits = Deposit::query()
            ->with(['units'])
            ->where('client_id', $clientId)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->latest('deposit_date')
            ->paginate(20);

        $totals = Deposit::query()
            ->where('client_id', $clientId)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->selectRaw('count(*) as count, coalesce(sum(total),0) as total, coalesce(sum(advanced),0) as advanced, coalesce(sum(discount),0) as discount')
            ->first();

        return $this->page($deposits, DepositResource::collection($deposits)->resolve(), [
            'totals' => [
                'count' => (int) ($totals->count ?? 0),
                'total' => (int) ($totals->total ?? 0),
                'advanced' => (int) ($totals->advanced ?? 0),
                'discount' => (int) ($totals->discount ?? 0),
            ],
        ]);
    }

    public function ranking(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request, defaultToday: false);

        return $this->ok(Metrics::ranking($range, (int) $request->get('limit', 20))->values(), $range->toArray());
    }

    public function discounts(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number'])
            ->where('discount', '>', 0)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->latest('deposit_date')
            ->paginate(50);

        $sum = (int) Deposit::query()
            ->where('discount', '>', 0)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->sum('discount');

        return $this->page($items, DepositResource::collection($items)->resolve(), [
            'discount_total' => $sum,
        ]);
    }

    public function dailyBalance(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);
        $sales = Metrics::depositTotals($range);
        $cash = Metrics::cashTotals($range);

        return $this->ok($range->toArray() + [
            'sales' => $sales,
            'cash' => $cash,
            'net' => $sales['total'] - $sales['discount'],
            'rest' => $sales['left_to_pay'],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);
        $sales = Metrics::depositTotals($range);

        $byEtat = Deposit::query()
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->selectRaw('etat, count(*) as count, coalesce(sum(total),0) as total')
            ->groupBy('etat')
            ->get();

        return $this->ok($range->toArray() + $sales + [
            'by_etat' => $byEtat,
        ]);
    }

    public function consolidated(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewAllAgencies(), 403);

        $tenant = app(TenantContext::class);
        $previousAgency = $tenant->agencyId;
        $tenant->agencyId = null;

        $range = DateRange::fromRequest($request);
        $agencies = Agency::query()
            ->where('pressing_id', $request->user()->pressing_id)
            ->get(['id', 'name']);

        $rows = Metrics::byAgency($range, $agencies);
        $tenant->agencyId = $previousAgency;

        return $this->ok($range->toArray() + [
            'agencies' => $rows->values(),
            'totals' => [
                'deposits' => $rows->sum('deposits'),
                'retrieves' => $rows->sum('retrieves'),
                'receipts' => $rows->sum('receipts'),
                'balance' => $rows->sum('balance'),
            ],
        ]);
    }
}
