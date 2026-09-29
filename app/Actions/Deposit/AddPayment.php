<?php

namespace App\Actions\Deposit;

use App\Actions\Loyalty\RecordLoyalty;
use App\Actions\Wallet\RecordWallet;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddPayment
{
    public function handle(User $cashier, Deposit $deposit, array $payload): Deposit
    {
        $amount = (int) $payload['amount'];

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être positif.']);
        }

        $uuid = $payload['client_uuid'] ?? (string) Str::uuid();

        $existing = Transaction::withTrashed()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return $deposit->fresh(['units', 'client', 'transactions']);
        }

        try {
            return DB::transaction(function () use ($cashier, $deposit, $payload, $amount, $uuid) {
                /** @var Deposit $locked */
                $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

                if ($amount > (int) $locked->left_to_pay) {
                    throw ValidationException::withMessages(['amount' => 'Le montant dépasse le reste à payer.']);
                }

                $dup = Transaction::withTrashed()->where('client_uuid', $uuid)->lockForUpdate()->first();
                if ($dup) {
                    return $locked->fresh(['units', 'client', 'transactions']);
                }

                $method = $payload['payment_method'] ?? 'cash';

                if ($method === 'wallet') {
                    $locked->loadMissing('client');
                    app(RecordWallet::class)->debit($locked->client, $amount, 'paiement dépôt', $locked->id);
                }

                Transaction::query()->create([
                    'client_uuid' => $uuid,
                    'deposit_id' => $locked->id,
                    'pressing_id' => $locked->pressing_id,
                    'agency_id' => $locked->agency_id,
                    'user_id' => $cashier->id,
                    'amount' => $amount,
                    'type' => 'in',
                    'payment_method' => $method,
                    'transaction_date' => $payload['transaction_date'] ?? now(),
                ]);

                $locked->advanced += $amount;
                $locked->left_to_pay = max(0, $locked->total - $locked->advanced);
                $locked->save();

                app(RecordLoyalty::class)->earn($locked, $amount);

                AuditLog::record('deposit.payment', $locked, ['amount' => $amount]);

                return $locked->fresh(['units', 'client', 'transactions']);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $deposit->fresh(['units', 'client', 'transactions']);
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $message = $e->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
