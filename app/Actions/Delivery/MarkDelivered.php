<?php

namespace App\Actions\Delivery;

use App\Actions\Deposit\AddPayment;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarkDelivered
{
    public function handle(User $livreur, Deposit $deposit, array $payload): Deposit
    {
        $type = $payload['confirmation_type'] ?? 'code';
        $value = $payload['confirmation_value'] ?? null;

        if (! in_array($type, ['signature', 'code'], true)) {
            throw ValidationException::withMessages([
                'confirmation_type' => 'Type de confirmation invalide (signature|code).',
            ]);
        }

        if ($type === 'code' && (! is_string($value) || strlen(trim($value)) < 4)) {
            throw ValidationException::withMessages([
                'confirmation_value' => 'Le code de confirmation client est requis (min. 4 caractères).',
            ]);
        }

        if ($type === 'signature' && (! is_string($value) || trim($value) === '')) {
            throw ValidationException::withMessages([
                'confirmation_value' => 'La signature client est requise.',
            ]);
        }

        return DB::transaction(function () use ($livreur, $deposit, $payload, $type, $value) {
            /** @var Deposit $locked */
            $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            // Idempotent : rejouer une livraison déjà faite.
            if ($locked->delivery_status === Deposit::DELIVERY_DELIVERED) {
                return $locked->fresh(['units', 'client', 'transactions']);
            }

            if (! in_array($locked->etat, [Deposit::ETAT_CLASSED, Deposit::ETAT_TREATED], true)) {
                throw ValidationException::withMessages([
                    'deposit' => 'Le dépôt doit être classé (ou traité) avant livraison.',
                ]);
            }

            if ($locked->livreur_id && (int) $locked->livreur_id !== (int) $livreur->id
                && ! $livreur->canViewAllAgencies()) {
                throw ValidationException::withMessages([
                    'deposit' => 'Ce dépôt est assigné à un autre livreur.',
                ]);
            }

            $collect = (int) ($payload['collect_amount'] ?? 0);

            if ($collect > 0) {
                if ($collect > (int) $locked->left_to_pay) {
                    throw ValidationException::withMessages([
                        'collect_amount' => 'Le montant dépasse le solde restant.',
                    ]);
                }

                // UUID paiement dérivé pour rester idempotent si la livraison est rejouée.
                $payUuid = $payload['client_uuid'] ?? (string) Str::uuid();

                app(AddPayment::class)->handle($livreur, $locked, [
                    'amount' => $collect,
                    'payment_method' => $payload['payment_method'] ?? 'cash',
                    'client_uuid' => $payUuid,
                ]);
                $locked->refresh();
            }

            if (! empty($payload['require_settled']) && ! $locked->isSettled()) {
                throw ValidationException::withMessages([
                    'deposit' => 'Le solde doit être soldé avant de marquer livré.',
                ]);
            }

            $locked->update([
                'delivery_status' => Deposit::DELIVERY_DELIVERED,
                'livreur_id' => $livreur->id,
                'delivered_at' => now(),
                'delivery_confirmation_type' => $type,
                'delivery_confirmation_value' => $value,
                'delivery_confirmed_at' => now(),
                'status' => false,
                'retrieved_at' => $locked->retrieved_at ?? now(),
                'receiver_id' => $livreur->id,
                'receiver_name' => $payload['receiver_name'] ?? $locked->client?->fullname,
            ]);

            AuditLog::record('deposit.delivered', $locked, [
                'confirmation_type' => $type,
                'collected' => $collect,
            ]);

            return $locked->fresh(['units', 'client', 'transactions']);
        });
    }
}
