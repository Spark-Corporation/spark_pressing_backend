<?php

namespace App\Actions\Loyalty;

use App\Models\Client;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\Pressing;
use App\Support\Loyalty\PointRate;

class RecordLoyalty
{
    public function earn(Deposit $deposit, int $amountPaid): int
    {
        $pressing = $deposit->pressing ?? Pressing::query()->find($deposit->pressing_id);
        $rate = (int) ($pressing?->loyalty_points_rate ?? 0);

        if ($rate <= 0 || $amountPaid <= 0) {
            return 0;
        }

        $points = intdiv($amountPaid * $rate, 1000);

        if ($points <= 0) {
            return 0;
        }

        $client = Client::withoutGlobalScopes()->find($deposit->client_id);
        $client->increment('loyalty_points', $points);
        $deposit->increment('points_earned', $points);

        LoyaltyLedger::query()->create([
            'pressing_id' => $deposit->pressing_id,
            'client_id' => $deposit->client_id,
            'deposit_id' => $deposit->id,
            'type' => 'earn',
            'points' => $points,
            'amount_xaf' => $amountPaid,
            'reason' => 'Paiement dépôt '.$deposit->code,
        ]);

        return $points;
    }

    public function redeem(Client $client, Pressing $pressing, int $points, ?Deposit $deposit = null): int
    {
        if ($points <= 0) {
            return 0;
        }

        $rate = PointRate::for($client, $pressing);

        if ($points < $rate->quota || $client->loyalty_points < $points) {
            return 0;
        }

        $discount = $rate->discountFor($points);
        $client->decrement('loyalty_points', $points);

        LoyaltyLedger::query()->create([
            'pressing_id' => $pressing->id,
            'client_id' => $client->id,
            'deposit_id' => $deposit?->id,
            'type' => 'redeem',
            'points' => -$points,
            'amount_xaf' => $discount,
            'reason' => 'Conversion points en remise',
        ]);

        return $discount;
    }
}
