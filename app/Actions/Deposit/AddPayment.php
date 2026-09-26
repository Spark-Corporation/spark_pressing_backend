<?php

namespace App\Actions\Deposit;

use App\Actions\Loyalty\RecordLoyalty;
use App\Actions\Wallet\RecordWallet;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
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

        if ($amount > (int) $deposit->left_to_pay) {
            throw ValidationException::withMessages(['amount' => 'Le montant dépasse le reste à payer.']);
        }

        $uuid = $payload['client_uuid'] ?? (string) Str::uuid();
        $existing = Transaction::withTrashed()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return $deposit->fresh(['units', 'client', 'transactions']);
        }

        return DB::transaction(function () use ($cashier, $deposit, $payload, $amount, $uuid) {
            $method = $payload['payment_method'] ?? 'cash';

            if ($method === 'wallet') {
                $deposit->loadMissing('client');
                app(RecordWallet::class)->debit($deposit->client, $amount, 'paiement dépôt', $deposit->id);
            }

            Transaction::query()->create([
                'client_uuid' => $uuid,
                'deposit_id' => $deposit->id,
                'pressing_id' => $deposit->pressing_id,
                'agency_id' => $deposit->agency_id,
                'user_id' => $cashier->id,
                'amount' => $amount,
                'type' => 'in',
                'payment_method' => $method,
                'transaction_date' => $payload['transaction_date'] ?? now(),
            ]);

            $deposit->advanced += $amount;
            $deposit->left_to_pay = max(0, $deposit->total - $deposit->advanced);
            $deposit->save();

            app(RecordLoyalty::class)->earn($deposit, $amount);

            AuditLog::record('deposit.payment', $deposit, ['amount' => $amount]);

            return $deposit->fresh(['units', 'client', 'transactions']);
        });
    }
}
