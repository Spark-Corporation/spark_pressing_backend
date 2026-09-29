<?php

namespace App\Actions\Delivery;

use App\Models\AuditLog;
use App\Models\DeliveryRound;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignToRound
{
    public function handle(User $actor, DeliveryRound $round, array $depositIds): DeliveryRound
    {
        if ((int) $round->pressing_id !== (int) $actor->pressing_id) {
            throw ValidationException::withMessages(['round' => 'Tournée hors pressing.']);
        }

        return DB::transaction(function () use ($actor, $round, $depositIds) {
            $deposits = Deposit::query()
                ->whereIn('id', $depositIds)
                ->where('pressing_id', $actor->pressing_id)
                ->get();

            if ($deposits->count() !== count(array_unique($depositIds))) {
                throw ValidationException::withMessages([
                    'deposit_ids' => 'Un ou plusieurs dépôts sont introuvables.',
                ]);
            }

            foreach ($deposits as $deposit) {
                if (! in_array($deposit->etat, [Deposit::ETAT_CLASSED, Deposit::ETAT_TREATED], true)) {
                    throw ValidationException::withMessages([
                        'deposit_ids' => "Le dépôt {$deposit->code} n'est pas prêt à livrer.",
                    ]);
                }

                if (! $deposit->isOpen()) {
                    throw ValidationException::withMessages([
                        'deposit_ids' => "Le dépôt {$deposit->code} est déjà clôturé.",
                    ]);
                }

                $deposit->update([
                    'delivery_round_id' => $round->id,
                    'delivery_zone_id' => $round->delivery_zone_id,
                    'livreur_id' => $round->livreur_id,
                    'delivery_status' => Deposit::DELIVERY_ASSIGNED,
                ]);
            }

            if ($round->status === DeliveryRound::STATUS_OPEN) {
                $round->update(['status' => DeliveryRound::STATUS_IN_PROGRESS]);
            }

            AuditLog::record('delivery.round.assigned', $round, [
                'deposit_ids' => $depositIds,
            ]);

            return $round->fresh(['deposits', 'livreur', 'zone']);
        });
    }
}
