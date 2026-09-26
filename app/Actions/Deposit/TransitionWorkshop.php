<?php

namespace App\Actions\Deposit;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TransitionWorkshop
{
    public function handle(User $actor, Deposit $deposit, string $to): Deposit
    {
        $pressing = $deposit->pressing;
        $from = $deposit->etat;

        $allowed = $this->allowedTransitions($pressing->workflow_laveur_enabled, $pressing->workflow_classeur_enabled);

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'etat' => "Transition $from → $to non autorisée pour ce pressing.",
            ]);
        }

        $deposit->etat = $to;

        if ($to === Deposit::ETAT_IN_PROGRESS) {
            $deposit->laveur_id = $actor->id;
        }

        if ($to === Deposit::ETAT_CLASSED) {
            $deposit->classeur_id = $actor->id;
        }

        $deposit->save();

        AuditLog::record('deposit.workshop', $deposit, ['from' => $from, 'to' => $to]);

        return $deposit->fresh(['units', 'client']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function allowedTransitions(bool $laveur, bool $classeur): array
    {
        $afterWaiting = $laveur ? [Deposit::ETAT_IN_PROGRESS] : [Deposit::ETAT_TREATED];
        $afterProgress = [Deposit::ETAT_TREATED];
        $afterTreated = $classeur ? [Deposit::ETAT_CLASSED] : [];

        return [
            Deposit::ETAT_WAITING => $afterWaiting,
            Deposit::ETAT_IN_PROGRESS => $afterProgress,
            Deposit::ETAT_TREATED => $afterTreated,
            Deposit::ETAT_CLASSED => [],
        ];
    }
}
