<?php

namespace App\Http\Controllers\Api\V1\Deposits;

use App\Actions\Deposit\AddPayment;
use App\Actions\Deposit\CreateDeposit;
use App\Actions\Deposit\RetrieveDeposit;
use App\Actions\Deposit\RetrievePartial;
use App\Actions\Deposit\TransitionWorkshop;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Support\Documents\TicketDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('Dépôts', 'Cycle dépôt → paiement → atelier → retrait.')]
class DepositController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deposits = DepositQuery::filtered($request)
            ->latest('deposit_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function due(Request $request): JsonResponse
    {
        $deposits = DepositQuery::filtered($request->merge(['due' => 1]))
            ->latest('retrieve_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function retrieved(Request $request): JsonResponse
    {
        $deposits = DepositQuery::retrieved($request)
            ->latest('retrieved_at')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function store(Request $request, CreateDeposit $action): JsonResponse
    {
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'client_id' => ['required', 'exists:clients,id'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'promo_code' => ['nullable', 'string'],
            'promo_special_code' => ['nullable', 'string'],
            'points_to_redeem' => ['nullable', 'integer', 'min:0'],
            'with_collection' => ['sometimes', 'boolean'],
            'with_delivery' => ['sometimes', 'boolean'],
            'collection_fee' => ['nullable', 'integer', 'min:0'],
            'delivery_fee' => ['nullable', 'integer', 'min:0'],
            'advanced' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'deposit_date' => ['nullable', 'date'],
            'retrieve_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.article_id' => ['required', 'exists:articles,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.type_action' => ['nullable', 'integer', 'in:0,1,2'],
            'lines.*.pricing_type' => ['nullable', 'in:piece,kilo'],
            'lines.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.designation' => ['nullable', 'string'],
            'lines.*.state' => ['nullable', 'string'],
            'lines.*.render_id' => ['nullable', 'integer'],
        ]);

        $deposit = $action->handle($request->user(), $payload);

        return $this->created((new DepositResource($deposit))->resolve());
    }

    public function show(Deposit $deposit): JsonResponse
    {
        return $this->ok((new DepositResource($deposit->load([
            'client:id,fullname,phone_number,code,wallet_balance,sponsor_code',
            'units',
            'transactions',
            'cashier:id,fullname',
        ])))->resolve());
    }

    public function pay(Request $request, Deposit $deposit, AddPayment $action): JsonResponse
    {
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'transaction_date' => ['nullable', 'date'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function retrieve(Request $request, Deposit $deposit, RetrieveDeposit $action): JsonResponse
    {
        $payload = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:191'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function transition(Request $request, Deposit $deposit, TransitionWorkshop $action): JsonResponse
    {
        $payload = $request->validate([
            'etat' => ['required', 'in:waiting,in_progress,treated,classed'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload['etat']);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function retrievePartial(Request $request, Deposit $deposit, RetrievePartial $action): JsonResponse
    {
        $payload = $request->validate([
            'units' => ['required', 'array', 'min:1'],
            'units.*.id' => ['required', 'integer'],
            'units.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload['units']);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function destroy(Deposit $deposit): JsonResponse
    {
        $deposit->delete();
        AuditLog::record('deposit.deleted', $deposit);

        return $this->ok(['deleted' => true]);
    }

    public function ready(): JsonResponse
    {
        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code'])
            ->withSum('units as items_count', 'quantity')
            ->where('status', true)
            ->whereIn('etat', ['treated', 'classed'])
            ->latest('retrieve_date')
            ->paginate(20);

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    public function document(Request $request, Deposit $deposit, TicketDocument $tickets): JsonResponse|Response
    {
        $type = $request->query('type', 'ticket');
        $payload = $tickets->payload($request, $deposit, $type);

        if ($request->query('format') === 'pdf') {
            return $tickets->pdfResponse($deposit, $type);
        }

        $payload['deposit'] = (new DepositResource($deposit))->resolve();

        return $this->ok($payload);
    }
}
