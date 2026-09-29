<?php

namespace App\Http\Controllers\Api\V1\Deposits;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Dépôts', 'Lookup QR atelier / POS.')]
class QrLookupController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $deposit = Deposit::query()
            ->where('qr_token', $token)
            ->with([
                'client:id,fullname,phone_number,code',
                'units',
                'agency:id,name,currency',
            ])
            ->firstOrFail();

        return $this->ok((new DepositResource($deposit))->resolve());
    }
}
