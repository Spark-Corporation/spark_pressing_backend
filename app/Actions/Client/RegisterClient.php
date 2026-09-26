<?php

namespace App\Actions\Client;

use App\Actions\Wallet\RecordWallet;
use App\Models\Client;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterClient
{
    public function handle(array $data, bool $withoutGlobalScopes = false): Client
    {
        $query = $withoutGlobalScopes ? Client::withoutGlobalScopes() : Client::query();

        if ($query->where('pressing_id', $data['pressing_id'])->where('phone_number', $data['phone_number'])->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => 'Ce numéro est déjà inscrit sur ce pressing.',
            ]);
        }

        $sponsorCode = $data['sponsor_code'] ?? null;
        unset($data['sponsor_code']);

        $referredBy = null;
        if ($sponsorCode) {
            $referredBy = Client::withoutGlobalScopes()
                ->where('pressing_id', $data['pressing_id'])
                ->where('sponsor_code', $sponsorCode)
                ->first();

            if (! $referredBy) {
                throw ValidationException::withMessages([
                    'sponsor_code' => 'Code de parrainage invalide.',
                ]);
            }

            $data['referred_by_id'] = $referredBy->id;
        }

        $data['status'] = $data['status'] ?? true;
        $data['sponsor_code'] = $this->uniqueSponsorCode((int) $data['pressing_id']);
        $data['wallet_balance'] = $data['wallet_balance'] ?? 0;

        $client = Client::withoutGlobalScopes()->create($data);
        $client->code = 'C'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT);
        $client->save();

        $bonus = (int) config('spark.referral_bonus_xaf', 0);
        if ($referredBy && $bonus > 0) {
            app(RecordWallet::class)->credit($referredBy, $bonus, 'parrainage');
        }

        return $client;
    }

    public function uniqueSponsorCode(int $pressingId): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (
            Client::withoutGlobalScopes()
                ->where('pressing_id', $pressingId)
                ->where('sponsor_code', $code)
                ->exists()
        );

        return $code;
    }
}
