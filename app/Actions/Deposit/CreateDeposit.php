<?php

namespace App\Actions\Deposit;

use App\Actions\Loyalty\RecordLoyalty;
use App\Actions\Wallet\RecordWallet;
use App\Models\Agency;
use App\Models\AgencyPrice;
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
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateDeposit
{
    public function handle(User $cashier, array $payload): Deposit
    {
        $clientUuid = $payload['client_uuid'] ?? (string) Str::uuid();

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

        try {
            return DB::transaction(function () use ($cashier, $payload, $clientUuid, $client, $pressing, $agency) {
                $existing = Deposit::withTrashed()
                    ->where('client_uuid', $clientUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing->load(['units', 'client', 'transactions']);
                }

                $depositDate = isset($payload['deposit_date'])
                    ? now()->parse($payload['deposit_date'])
                    : now();

                $lines = $this->buildLines($payload['lines'], $pressing, $agency, $depositDate);
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

                $maxHours = (int) (collect($lines)->max('default_hours') ?: $pressing->hours_classic);
                $preset = DeliveryHour::query()->where('status', true)->latest('id')->first();
                $hours = $preset
                    ? $preset->hoursForAction((int) (collect($lines)->max('type_action') ?? 0))
                    : $maxHours;

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

                $currency = Currency::normalize($agency->currency ?: config('spark.currency'));
                $qrToken = $pressing->qr_labels_enabled ? Str::lower(Str::random(24)) : null;

                $withDelivery = ! empty($payload['with_delivery']) || $delivery > 0 || ! empty($payload['delivery_zone_id']);

                $deposit = Deposit::query()->create([
                    'client_uuid' => $clientUuid,
                    'code' => $code,
                    'qr_token' => $qrToken,
                    'pressing_id' => $pressing->id,
                    'agency_id' => $agency->id,
                    'currency' => $currency,
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
                    'delivery_status' => $withDelivery ? Deposit::DELIVERY_PENDING : null,
                    'delivery_zone_id' => $payload['delivery_zone_id'] ?? null,
                    'delivery_address' => $payload['delivery_address'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ]);

                foreach ($lines as $line) {
                    unset($line['default_hours']);
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
                    'currency' => $currency,
                ]);

                return $deposit->fresh(['units', 'client', 'transactions']);
            });
        } catch (QueryException $e) {
            // Course concurrente sur client_uuid unique → rejouer le dépôt existant.
            if ($this->isUniqueViolation($e)) {
                $existing = Deposit::withTrashed()->where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    return $existing->load(['units', 'client', 'transactions']);
                }
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

    private function buildLines(array $lines, Pressing $pressing, Agency $agency, $at): array
    {
        $articleIds = collect($lines)->pluck('article_id')->filter()->unique()->values()->all();
        $serviceIds = collect($lines)->pluck('service_id')->filter()->unique()->values()->all();

        $articles = Article::query()
            ->whereIn('id', $articleIds)
            ->get()
            ->keyBy('id');

        $servicesById = Service::query()
            ->where('pressing_id', $pressing->id)
            ->where('status', true)
            ->when($serviceIds !== [], fn ($q) => $q->whereIn('id', $serviceIds))
            ->get()
            ->keyBy('id');

        $legacyServices = Service::query()
            ->where('pressing_id', $pressing->id)
            ->where('status', true)
            ->whereNotNull('legacy_type_action')
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (Service $s) => (int) $s->legacy_type_action)
            ->map(fn ($group) => $group->first());

        $priceRows = AgencyPrice::query()
            ->where('agency_id', $agency->id)
            ->whereIn('article_id', $articleIds)
            ->whereDate('valid_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $at);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        $built = [];

        foreach ($lines as $line) {
            $article = $articles->get($line['article_id']);
            if (! $article) {
                throw ValidationException::withMessages([
                    'lines' => 'Article introuvable: '.$line['article_id'],
                ]);
            }

            $pricing = $line['pricing_type'] ?? 'piece';

            if ($pricing === 'kilo' && $pressing->pricing_mode === 'piece') {
                throw ValidationException::withMessages([
                    'lines' => 'La tarification au kilo n\'est pas activée pour ce pressing.',
                ]);
            }

            [$service, $typeAction] = $this->resolveServiceFromCache(
                $line,
                $servicesById,
                $legacyServices
            );

            $unitPrice = isset($line['unit_price'])
                ? (int) $line['unit_price']
                : $this->resolveUnitPriceFromCache($article, $service, $typeAction, $pricing, $priceRows);

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $weight = isset($line['weight_kg']) ? (float) $line['weight_kg'] : null;
            $lineTotal = $pricing === 'kilo'
                ? (int) round($unitPrice * ($weight ?: 0))
                : $unitPrice * $quantity;

            $built[] = [
                'article_id' => $article->id,
                'service_id' => $service?->id,
                'designation' => $line['designation'] ?? $article->name,
                'pricing_type' => $pricing,
                'quantity' => $quantity,
                'weight_kg' => $weight,
                'type_action' => $typeAction,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'state' => $line['state'] ?? null,
                'render_id' => $line['render_id'] ?? null,
                'default_hours' => $service?->default_hours ?? $this->hoursForLegacy($pressing, $typeAction),
            ];
        }

        if ($built === []) {
            throw ValidationException::withMessages([
                'lines' => 'Le dépôt doit contenir au moins une ligne.',
            ]);
        }

        return $built;
    }

    private function resolveServiceFromCache(array $line, $servicesById, $legacyServices): array
    {
        if (! empty($line['service_id'])) {
            $service = $servicesById->get($line['service_id']);
            if (! $service) {
                throw ValidationException::withMessages([
                    'lines' => 'Service introuvable: '.$line['service_id'],
                ]);
            }

            return [$service, (int) ($service->legacy_type_action ?? 0)];
        }

        $type = (int) ($line['type_action'] ?? 0);

        return [$legacyServices->get($type), $type];
    }

    private function resolveUnitPriceFromCache(
        Article $article,
        ?Service $service,
        int $typeAction,
        string $pricing,
        $priceRows
    ): int {
        if ($service) {
            $row = $priceRows->first(
                fn (AgencyPrice $p) => (int) $p->article_id === (int) $article->id
                    && (int) $p->service_id === (int) $service->id
                    && $p->pricing_type === $pricing
            );

            if ($row) {
                return (int) $row->amount_minor;
            }
        }

        return $article->priceFor($typeAction, $pricing);
    }

    private function hoursForLegacy(Pressing $pressing, int $typeAction): int
    {
        return match ($typeAction) {
            1 => (int) $pressing->hours_express,
            2 => (int) $pressing->hours_repass,
            default => (int) $pressing->hours_classic,
        };
    }
}
