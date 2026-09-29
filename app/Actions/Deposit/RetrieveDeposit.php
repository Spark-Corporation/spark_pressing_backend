<?php

namespace App\Actions\Deposit;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetrieveDeposit
{
    public function handle(User $cashier, Deposit $deposit, array $payload = []): Deposit
    {
        return DB::transaction(function () use ($cashier, $deposit, $payload) {
            /** @var Deposit $locked */
            $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            // Idempotent : second retrait renvoie l'état déjà clôturé.
            if (! $locked->isOpen()) {
                return $locked->fresh(['units', 'client', 'transactions']);
            }

            $pressing = $locked->pressing;

            if ($pressing->block_retrieve_if_unpaid && ! $locked->isSettled()) {
                throw ValidationException::withMessages([
                    'deposit' => 'Retrait bloqué : solde impayé.',
                ]);
            }

            $locked->status = false;
            $locked->retrieved_at = now();
            $locked->receiver_id = $cashier->id;
            $locked->receiver_name = $payload['receiver_name'] ?? $locked->client?->fullname;
            $locked->save();

            AuditLog::record('deposit.retrieved', $locked, [
                'receiver_name' => $locked->receiver_name,
            ]);

            return $locked->fresh(['units', 'client', 'transactions']);
        });
    }
}
