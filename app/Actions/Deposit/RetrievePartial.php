<?php

namespace App\Actions\Deposit;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetrievePartial
{
    public function handle(User $cashier, Deposit $deposit, array $units): Deposit
    {
        if (! $deposit->isOpen()) {
            throw ValidationException::withMessages(['deposit' => 'Ce dépôt est déjà retiré.']);
        }

        $pressing = $deposit->pressing;
        if ($pressing->block_retrieve_if_unpaid && ! $deposit->isSettled()) {
            throw ValidationException::withMessages(['deposit' => 'Retrait bloqué : solde impayé.']);
        }

        return DB::transaction(function () use ($cashier, $deposit, $units) {
            foreach ($units as $row) {
                $unit = $deposit->units()->whereKey($row['id'])->firstOrFail();
                $qty = (int) $row['quantity'];
                $remaining = $unit->quantity - $unit->retrieve_quantity;

                if ($qty < 1 || $qty > $remaining) {
                    throw ValidationException::withMessages([
                        'units' => 'Quantité de retrait invalide pour '.$unit->designation,
                    ]);
                }

                $unit->retrieve_quantity += $qty;
                $unit->save();
            }

            $deposit->load('units');
            $allOut = $deposit->units->every(fn ($u) => $u->retrieve_quantity >= $u->quantity);

            if ($allOut) {
                $deposit->status = false;
                $deposit->retrieved_at = now();
                $deposit->receiver_id = $cashier->id;
                $deposit->save();
            }

            AuditLog::record('deposit.partial_retrieve', $deposit, ['units' => $units]);

            return $deposit->fresh(['units', 'client', 'transactions']);
        });
    }
}
