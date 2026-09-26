<?php

namespace App\Actions\Deposit;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RetrieveDeposit
{
    public function handle(User $cashier, Deposit $deposit, array $payload = []): Deposit
    {
        if (! $deposit->isOpen()) {
            throw ValidationException::withMessages(['deposit' => 'Ce dépôt est déjà retiré.']);
        }

        $pressing = $deposit->pressing;

        if ($pressing->block_retrieve_if_unpaid && ! $deposit->isSettled()) {
            throw ValidationException::withMessages([
                'deposit' => 'Retrait bloqué : solde impayé.',
            ]);
        }

        $deposit->status = false;
        $deposit->retrieved_at = now();
        $deposit->receiver_id = $cashier->id;
        $deposit->receiver_name = $payload['receiver_name'] ?? $deposit->client?->fullname;
        $deposit->save();

        AuditLog::record('deposit.retrieved', $deposit, [
            'receiver_name' => $deposit->receiver_name,
        ]);

        return $deposit->fresh(['units', 'client', 'transactions']);
    }
}
