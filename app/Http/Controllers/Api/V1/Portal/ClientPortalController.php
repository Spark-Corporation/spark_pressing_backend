<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Espace client')]
class ClientPortalController extends Controller
{
    public function deposits(Request $request): JsonResponse
    {
        $deposits = Deposit::query()
            ->with('units')
            ->withSum('units as items_count', 'quantity')
            ->where('client_id', $request->user()->id)
            ->where('status', true)
            ->latest('deposit_date')
            ->paginate(20);

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function retrieves(Request $request): JsonResponse
    {
        $deposits = Deposit::query()
            ->with('units')
            ->where('client_id', $request->user()->id)
            ->where('status', false)
            ->latest('retrieved_at')
            ->paginate(20);

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function show(Request $request, Deposit $deposit): JsonResponse
    {
        abort_unless((int) $deposit->client_id === (int) $request->user()->id, 403);

        return $this->ok((new DepositResource($deposit->load(['units', 'transactions'])))->resolve());
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->ok((new ClientResource($request->user()))->resolve());
    }

    public function loyalty(Request $request): JsonResponse
    {
        $client = $request->user();

        return $this->ok([
            'loyalty_points' => $client->loyalty_points,
            'wallet_balance' => (int) $client->wallet_balance,
            'sponsor_code' => $client->sponsor_code,
            'referrals' => $client->referrals()->count(),
            'ledger' => LoyaltyLedger::query()->where('client_id', $client->id)->latest()->limit(50)->get(),
            'wallet_ledger' => WalletLedger::query()->where('client_id', $client->id)->latest()->limit(50)->get(),
        ]);
    }
}
