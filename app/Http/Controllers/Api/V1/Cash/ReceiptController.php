<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Support\DateRange;
use App\Support\Reporting\Metrics;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Recettes')]
class ReceiptController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        return $this->ok(Metrics::cashTotals(DateRange::fromRequest($request)) + [
            'scope' => 'agency',
        ]);
    }

    public function general(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewAllAgencies(), 403);

        $tenant = app(TenantContext::class);
        $previous = $tenant->agencyId;
        $tenant->agencyId = null;

        $data = Metrics::cashTotals(DateRange::fromRequest($request)) + [
            'sales' => Metrics::depositTotals(DateRange::fromRequest($request)),
            'scope' => 'pressing',
        ];

        $tenant->agencyId = $previous;

        return $this->ok($data);
    }

    public function close(Request $request): JsonResponse
    {
        $data = Metrics::cashTotals(DateRange::fromRequest($request));

        return $this->ok($data + [
            'reconciled' => true,
            'formula' => 'receipts + cash_in - cash_out',
        ]);
    }

    public function unpaid(): JsonResponse
    {
        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code'])
            ->withSum('units as items_count', 'quantity')
            ->where('left_to_pay', '>', 0)
            ->where('status', true)
            ->latest()
            ->paginate(50);

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    public function settled(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code'])
            ->where('left_to_pay', 0)
            ->whereBetween('deposit_date', [$range->from, $range->to])
            ->latest()
            ->paginate(50);

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    public function transactions(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        $items = Transaction::query()
            ->with(['deposit:id,code,total,client_id'])
            ->whereBetween('transaction_date', [$range->from, $range->to])
            ->latest('transaction_date')
            ->paginate(50);

        return $this->page($items, $items->items());
    }
}
