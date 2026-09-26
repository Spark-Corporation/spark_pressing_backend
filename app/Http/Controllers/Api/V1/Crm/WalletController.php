<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Actions\Wallet\RecordWallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Clients')]
class WalletController extends Controller
{
    public function show(Client $client): JsonResponse
    {
        return $this->ok([
            'client' => (new ClientResource($client))->resolve(),
            'wallet_balance' => (int) $client->wallet_balance,
            'ledger' => WalletLedger::query()
                ->where('client_id', $client->id)
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    public function credit(Request $request, Client $client, RecordWallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $wallet->credit($client, $data['amount'], $data['reason'] ?? 'ajustement');

        return $this->ok([
            'wallet_balance' => (int) $client->fresh()->wallet_balance,
        ]);
    }

    public function debit(Request $request, Client $client, RecordWallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $wallet->debit($client, $data['amount'], $data['reason'] ?? 'ajustement');

        return $this->ok([
            'wallet_balance' => (int) $client->fresh()->wallet_balance,
        ]);
    }
}
