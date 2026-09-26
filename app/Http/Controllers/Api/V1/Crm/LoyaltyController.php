<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyLedger;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group('Clients')]
class LoyaltyController extends Controller
{
    public function show(Client $client): JsonResponse
    {
        $ledger = LoyaltyLedger::query()
            ->where('client_id', $client->id)
            ->latest()
            ->limit(50)
            ->get();

        return $this->ok([
            'client_id' => $client->id,
            'loyalty_points' => $client->loyalty_points,
            'ledger' => $ledger,
        ]);
    }
}
