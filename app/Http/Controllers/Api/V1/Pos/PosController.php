<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Actions\Deposit\AddPayment;
use App\Actions\Deposit\CreateDeposit;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PressingResource;
use App\Models\Article;
use App\Models\Client;
use App\Models\CodeSuffix;
use App\Models\DeliveryHour;
use App\Models\LaundryStatus;
use App\Models\Pressing;
use App\Models\Promo;
use App\Models\PromoSpecial;
use App\Models\Render;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Group;

#[Group('POS')]
class PosController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $pressing = Pressing::query()->findOrFail($user->pressing_id);

        return $this->ok([
            'generated_at' => now()->toIso8601String(),
            'pressing' => (new PressingResource($pressing))->resolve(),
            'agency_id' => app(TenantContext::class)->agencyId ?? $user->agency_id,
            'articles' => ArticleResource::collection(
                Article::query()
                    ->where('status', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'classic_price', 'express_price', 'repass_price', 'classic_price_kilo', 'express_price_kilo', 'repass_price_kilo', 'status'])
            )->resolve(),
            'clients' => ClientResource::collection(
                Client::query()
                    ->where('status', true)
                    ->latest('id')
                    ->limit(100)
                    ->get(['id', 'code', 'fullname', 'phone_number', 'email', 'loyalty_points', 'wallet_balance', 'sponsor_code', 'status', 'pressing_id', 'agency_id'])
            )->resolve(),
            'promos' => Promo::query()->where('status', true)->get(['id', 'code', 'rate_percent', 'ends_at', 'quota', 'used']),
            'promo_specials' => PromoSpecial::query()->where('status', true)->get(['id', 'code', 'rate_percent', 'ends_at', 'max_clients', 'used']),
            'delivery_hours' => DeliveryHour::query()->where('status', true)->latest('id')->first(),
            'code_suffixes' => CodeSuffix::query()->where('status', true)->get(['id', 'agency_id', 'title']),
            'laundry_statuses' => LaundryStatus::query()->where('status', true)->get(['id', 'title', 'status']),
            'renders' => Render::query()->where('status', true)->get(['id', 'title', 'status']),
        ]);
    }

    public function sync(Request $request, CreateDeposit $create, AddPayment $pay): JsonResponse
    {
        $payload = $request->validate([
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.type' => ['required', 'in:deposit,payment'],
            'operations.*.payload' => ['required', 'array'],
            'operations.*.client_uuid' => ['required', 'uuid'],
        ]);

        $results = [];

        DB::transaction(function () use ($payload, $request, $create, $pay, &$results) {
            foreach ($payload['operations'] as $operation) {
                $opPayload = $operation['payload'] + ['client_uuid' => $operation['client_uuid']];

                if ($operation['type'] === 'deposit') {
                    $deposit = $create->handle($request->user(), $opPayload);
                    $results[] = [
                        'type' => 'deposit',
                        'client_uuid' => $operation['client_uuid'],
                        'id' => $deposit->id,
                        'code' => $deposit->code,
                        'replayed' => false,
                    ];

                    continue;
                }

                $depositId = $opPayload['deposit_id'] ?? null;
                $deposit = \App\Models\Deposit::query()->findOrFail($depositId);
                $deposit = $pay->handle($request->user(), $deposit, $opPayload);
                $results[] = [
                    'type' => 'payment',
                    'client_uuid' => $operation['client_uuid'],
                    'id' => $deposit->id,
                    'replayed' => false,
                ];
            }
        });

        return $this->ok(['operations' => $results]);
    }
}
