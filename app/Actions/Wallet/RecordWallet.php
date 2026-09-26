<?php

namespace App\Actions\Wallet;

use App\Models\Client;
use App\Models\WalletLedger;
use Illuminate\Validation\ValidationException;

class RecordWallet
{
    public function credit(Client $client, int $amount, string $reason, ?int $depositId = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $client->increment('wallet_balance', $amount);

        WalletLedger::query()->create([
            'pressing_id' => $client->pressing_id,
            'client_id' => $client->id,
            'deposit_id' => $depositId,
            'type' => 'credit',
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    public function debit(Client $client, int $amount, string $reason, ?int $depositId = null): void
    {
        if ($amount <= 0) {
            return;
        }

        if ((int) $client->wallet_balance < $amount) {
            throw ValidationException::withMessages([
                'payment_method' => 'Solde portefeuille insuffisant.',
            ]);
        }

        $client->decrement('wallet_balance', $amount);

        WalletLedger::query()->create([
            'pressing_id' => $client->pressing_id,
            'client_id' => $client->id,
            'deposit_id' => $depositId,
            'type' => 'debit',
            'amount' => -$amount,
            'reason' => $reason,
        ]);
    }
}
