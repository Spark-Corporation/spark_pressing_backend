<?php

namespace App\Actions\Deposit;

use App\Actions\Loyalty\RecordLoyalty;
use App\Actions\Wallet\RecordWallet;
use App\Models\Agency;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CodeSuffix;
use App\Models\DeliveryHour;
use App\Models\Deposit;
use App\Models\DepositUnit;
use App\Models\LoyalGroup;
use App\Models\Pressing;
use App\Models\Promo;
use App\Models\PromoSpecial;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateDeposit
{
    public function handle(User $cashier, array $payload): Deposit
    {
        $clientUuid = $payload['client_uuid'] ?? (string) Str::uuid();

        $existing = Deposit::withTrashed()->where('client_uuid', $clientUuid)->first();
        if ($existing) {
            return $existing->load(['units', 'client', 'transactions']);
        }

        $client = Client::query()->findOrFail($payload['client_id']);

        if ((int) $client->pressing_id !== (int) $cashier->pressing_id) {
            throw ValidationException::withMessages([
                'client_id' => 'Ce client n\'appartient pas à votre pressing.',
            ]);
        }

        $tenant = app(TenantContext::class);
        $agencyId = $tenant->agencyId ?? $cashier->agency_id;

        if (! $agencyId || ! $cashier->canAccessAgency((int) $agencyId)) {
            throw ValidationException::withMessages([
                'agency_id' => 'Agence d\'opération invalide. Envoyez X-Agency-Id.',
            ]);
        }

        $pressing = Pressing::query()->findOrFail($cashier->pressing_id);
        $agency = Agency::query()->findOrFail($agencyId);

        return DB::transaction(function () use ($cashier, $payload, $clientUuid, $client, $pressing, $agency) {
            $lines = $this->buildLines($payload['lines'], $pressing);
            $subtotal = collect($lines)->sum('line_total');
            $collection = ! empty($payload['with_collection']) ? (int) $pressing->collection_fee : (int) ($payload['collection_fee'] ?? 0);
            $delivery = ! empty($payload['with_delivery']) ? (int) $pressing->delivery_fee : (int) ($payload['delivery_fee'] ?? 0);
            $goods = $subtotal + $collection + $delivery;

            [$discount, $percent, $promoCode, $pointsRedeemed] = $this->resolveDiscount(
                $pressing,
                $client,
                $goods,
                $payload
            );

            $total = max(0, $goods - $discount);
            $advanced = min($total, (int) ($payload['advanced'] ?? 0));
            $left = $total - $advanced;

            $depositDate = isset($payload['deposit_date'])
                ? now()->parse($payload['deposit_date'])
                : now();

            $maxAction = collect($lines)->max('type_action') ?? 0;
            $preset = DeliveryHour::query()->where('status', true)->latest('id')->first();
            $hours = $preset
                ? $preset->hoursForAction((int) $maxAction)
                : match ((int) $maxAction) {
                    1 => $pressing->hours_express,
                    2 => $pressing->hours_repass,
                    default => $pressing->hours_classic,
                };

            $agency->increment('last_deposit_sequence');
            $agency->refresh();
            $suffix = $agency->code_suffix
                ?: CodeSuffix::query()->where('agency_id', $agency->id)->where('status', true)->latest('id')->value('title');
            $code = sprintf(
                '%s-%s%s',
                $agency->nextCodePrefix(),
                str_pad((string) $agency->last_deposit_sequence, 6, '0', STR_PAD_LEFT),
                $suffix ? '-'.$suffix : ''
            );

            $deposit = Deposit::query()->create([
                'client_uuid' => $clientUuid,
                'code' => $code,
                'pressing_id' => $pressing->id,
                'agency_id' => $agency->id,
                'client_id' => $client->id,
                'user_id' => $cashier->id,
                'deposit_date' => $depositDate,
                'retrieve_date' => $payload['retrieve_date'] ?? $depositDate->copy()->addHours($hours),
                'subtotal' => $subtotal,
                'collection_fee' => $collection,
                'delivery_fee' => $delivery,
                'discount' => $discount,
                'discount_percent' => $percent,
                'promo_code' => $promoCode,
                'points_redeemed' => $pointsRedeemed,
                'total' => $total,
                'advanced' => $advanced,
                'left_to_pay' => $left,
                'payment_method' => $payload['payment_method'] ?? null,
                'status' => true,
                'etat' => Deposit::ETAT_WAITING,
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                DepositUnit::query()->create(array_merge($line, [
                    'deposit_id' => $deposit->id,
                    'pressing_id' => $pressing->id,
                    'agency_id' => $agency->id,
                    'client_id' => $client->id,
                ]));
            }

            if ($advanced > 0) {
                $method = $payload['payment_method'] ?? 'cash';

                if ($method === 'wallet') {
                    app(RecordWallet::class)->debit($client, $advanced, 'paiement dépôt', $deposit->id);
                }

                Transaction::query()->create([
                    'client_uuid' => (string) Str::uuid(),
                    'deposit_id' => $deposit->id,
                    'pressing_id' => $pressing->id,
                    'agency_id' => $agency->id,
                    'user_id' => $cashier->id,
                    'amount' => $advanced,
                    'type' => 'in',
                    'payment_method' => $method,
                    'transaction_date' => $depositDate,
                ]);

                app(RecordLoyalty::class)->earn($deposit, $advanced);
            }

            $client->update(['last_deposit_at' => $depositDate]);

            AuditLog::record('deposit.created', $deposit, [
                'code' => $deposit->code,
                'total' => $deposit->total,
                'advanced' => $deposit->advanced,
                'agency_id' => $agency->id,
            ]);

            return $deposit->fresh(['units', 'client', 'transactions']);
        });
    }

    private function resolveDiscount(Pressing $pressing, Client $client, int $goods, array $payload): array
    {
        $manualPercent = (int) ($payload['discount_percent'] ?? 0);
        $manualAmount = (int) ($payload['discount'] ?? 0);
        $promoCode = $payload['promo_code'] ?? null;
        $promoPercent = 0;

        if ($promoCode) {
            $promo = Promo::query()->where('code', $promoCode)->first();
            if (! $promo || ! $promo->isUsable()) {
                throw ValidationException::withMessages(['promo_code' => 'Code promo invalide ou expiré.']);
            }
            $promoPercent = (int) $promo->rate_percent;
            $promo->increment('used');
        }

        $specialPercent = 0;
        if (! empty($payload['promo_special_code'])) {
            $special = PromoSpecial::query()->where('code', $payload['promo_special_code'])->first();
            if (! $special || ! $special->isUsable()) {
                throw ValidationException::withMessages(['promo_special_code' => 'Promo spéciale invalide ou expirée.']);
            }
            $specialPercent = (int) $special->rate_percent;
            $special->increment('used');
        }

        $groupPercent = (int) LoyalGroup::query()
            ->where('status', true)
            ->whereHas('clients', fn ($q) => $q->where('clients.id', $client->id))
            ->max('rate_percent');

        $percent = max($manualPercent, $promoPercent, $groupPercent, $specialPercent);
        $percentAmount = (int) floor($goods * $percent / 100);

        $points = (int) ($payload['points_to_redeem'] ?? 0);
        $pointsDiscount = 0;
        if ($points > 0) {
            $pointsDiscount = app(RecordLoyalty::class)->redeem($client, $pressing, $points);
            if ($pointsDiscount === 0) {
                throw ValidationException::withMessages(['points_to_redeem' => 'Points insuffisants ou seuil non atteint.']);
            }
        }

        $discount = max($manualAmount, $percentAmount) + $pointsDiscount;

        return [$discount, $percent, $promoCode, $points];
    }

    private function buildLines(array $lines, Pressing $pressing): array
    {
        $built = [];

        foreach ($lines as $line) {
            $article = Article::query()->findOrFail($line['article_id']);
            $type = (int) ($line['type_action'] ?? 0);
            $pricing = $line['pricing_type'] ?? 'piece';

            if ($pricing === 'kilo' && $pressing->pricing_mode === 'piece') {
                throw ValidationException::withMessages([
                    'lines' => 'La tarification au kilo n\'est pas activée pour ce pressing.',
                ]);
            }

            $unitPrice = isset($line['unit_price'])
                ? (int) $line['unit_price']
                : $article->priceFor($type, $pricing);

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $weight = isset($line['weight_kg']) ? (float) $line['weight_kg'] : null;
            $lineTotal = $pricing === 'kilo'
                ? (int) round($unitPrice * ($weight ?: 0))
                : $unitPrice * $quantity;

            $built[] = [
                'article_id' => $article->id,
                'designation' => $line['designation'] ?? $article->name,
                'pricing_type' => $pricing,
                'quantity' => $quantity,
                'weight_kg' => $weight,
                'type_action' => $type,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'state' => $line['state'] ?? null,
                'render_id' => $line['render_id'] ?? null,
            ];
        }

        if ($built === []) {
            throw ValidationException::withMessages([
                'lines' => 'Le dépôt doit contenir au moins une ligne.',
            ]);
        }

        return $built;
    }
}
