<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyResource;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PressingResource;
use App\Http\Resources\UserResource;
use App\Models\Agency;
use App\Models\Article;
use App\Models\Client;
use App\Models\CodeSuffix;
use App\Models\DeliveryHour;
use App\Models\Deposit;
use App\Models\LaundryStatus;
use App\Models\Pressing;
use App\Models\Promo;
use App\Models\PromoSpecial;
use App\Models\Render;
use App\Models\Service;
use App\Models\SyncBatch;
use App\Support\Deposits\DepositPresenter;
use App\Support\Sync\SyncOperationRunner;
use App\Support\Sync\SyncPresenter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Knuckles\Scribe\Attributes\Group;

#[Group('POS')]
class PosController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['agency', 'pressing', 'roles', 'agencies']);
        $pressing = Pressing::query()->findOrFail($user->pressing_id);
        $agencyId = app(TenantContext::class)->agencyId ?? $user->agency_id;
        $agency = Agency::query()->find($agencyId);

        return $this->ok([
            'generated_at' => now()->toIso8601String(),
            'user' => (new UserResource($user))->resolve(),
            'pressing' => (new PressingResource($pressing))->resolve(),
            'agency_id' => $agencyId,
            'agency' => $agency ? (new AgencyResource($agency))->resolve() : null,
            'agencies' => AgencyResource::collection(
                $user->relationLoaded('agencies') && $user->agencies->isNotEmpty()
                    ? $user->agencies
                    : Agency::query()->where('pressing_id', $user->pressing_id)->where('status', true)->get()
            )->resolve(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'articles' => ArticleResource::collection(
                Article::query()
                    ->where('status', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'classic_price', 'express_price', 'repass_price', 'classic_price_kilo', 'express_price_kilo', 'repass_price_kilo', 'status'])
            )->resolve(),
            'services' => Service::query()
                ->where('pressing_id', $user->pressing_id)
                ->where('status', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'code', 'legacy_type_action', 'sort_order', 'default_hours', 'status']),
            'clients' => ClientResource::collection(
                Client::query()
                    ->with(['agency:id,name'])
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
            'catalog' => $this->catalog($agencyId),
            'deposit_options' => $this->depositOptions($pressing),
            'sync' => $this->syncStatusPayload(),
        ]);
    }

    /** « Catalogue local » : articles disponibles dans l’agence, prix ajustés de l’agence. */
    private function catalog(?int $agencyId): array
    {
        $items = Article::query()
            ->with(['category:id,name,icon', 'agencySettings' => fn ($q) => $q->where('agency_id', $agencyId)])
            ->where('status', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (Article $a) => $a->agencySettings->first()?->available === false)
            ->map(function (Article $a) {
                $adjust = (int) ($a->agencySettings->first()?->price_adjust_percent ?? 0);
                $price = fn ($v) => $v === null ? null : (int) round($v * (100 + $adjust) / 100);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'code' => $a->code,
                    'icon' => $a->icon ?? $a->category?->icon,
                    'category' => $a->category?->name,
                    'billing_mode' => $a->billing_mode ?? 'piece',
                    'prices' => [
                        'piece' => ['classic' => $price($a->classic_price), 'express' => $price($a->express_price), 'repass' => $price($a->repass_price)],
                        'kilo' => ['classic' => $price($a->classic_price_kilo), 'express' => $price($a->express_price_kilo), 'repass' => $price($a->repass_price_kilo)],
                    ],
                    'kilo_tiers' => $a->kilo_tiers ?? [],
                    'express_available' => (int) $a->express_price > 0 || (int) $a->express_price_kilo > 0,
                    'delays' => ['classic' => $a->classic_hours, 'express' => $a->express_hours, 'repass' => $a->repass_hours],
                    'accepted_defects' => $a->accepted_defects ?? [],
                    'allow_discount' => (bool) ($a->allow_discount ?? true),
                    'price_editable' => (bool) $a->price_editable,
                ];
            })
            ->values();

        return [
            'agency_id' => $agencyId,
            'count' => $items->count(),
            'validated_at' => Article::query()->max('price_updated_at'),
            'items' => $items->all(),
        ];
    }

    /** Options de l’écran « Nouveau dépôt » : express, délais, état du vêtement, paiement. */
    private function depositOptions(Pressing $pressing): array
    {
        $percent = (int) $pressing->express_surcharge_percent;

        return [
            'express' => [
                'surcharge_percent' => $percent,
                'multiplier' => round(1 + $percent / 100, 2),
                'hours' => (int) $pressing->hours_express,
                'label' => sprintf('Tarif ×%s · Délai express %d h', rtrim(rtrim(number_format(1 + $percent / 100, 2, ',', ''), '0'), ','), $pressing->hours_express),
            ],
            'delays' => [
                ['code' => 'standard', 'label' => "Standard · {$pressing->hours_classic} h", 'hours' => (int) $pressing->hours_classic],
                ['code' => 'express', 'label' => "Express · {$pressing->hours_express} h", 'hours' => (int) $pressing->hours_express],
                ['code' => 'repass', 'label' => "Repassage · {$pressing->hours_repass} h", 'hours' => (int) $pressing->hours_repass],
            ],
            'defects' => collect(Deposit::DEFECTS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'payment_methods' => collect(DepositPresenter::PAYMENT_METHOD_LABELS)
                ->except('other')
                ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'discount_modes' => ['amount', 'percent'],
            'pricing_modes' => $pressing->pricing_mode === 'piece' ? ['piece'] : ['piece', 'kilo'],
        ];
    }

    public function syncStatus(Request $request, SyncPresenter $presenter): JsonResponse
    {
        $tenant = app(TenantContext::class);

        return $this->ok($presenter->status(
            (int) $request->user()->pressing_id,
            $tenant->agencyId,
            $request->query('device_code'),
        ));
    }

    /** @return array<string, mixed> */
    private function syncStatusPayload(): array
    {
        return app(SyncPresenter::class)->status(
            (int) (auth()->user()?->pressing_id ?? 0),
            app(TenantContext::class)->agencyId,
        );
    }

    /**
     * Retour en ligne : rejoue la file locale. Chaque opération renvoie `status`
     * (synced, reconciled, duplicate, conflict, failed), `local_ref` → `server_ref` et, en cas de
     * conflit, les options de décision ; `data.integrity` résume les contrôles.
     */
    public function sync(Request $request, SyncOperationRunner $runner, SyncPresenter $presenter): JsonResponse
    {
        $payload = $request->validate([
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.type' => ['required', 'in:deposit,payment'],
            'operations.*.payload' => ['required', 'array'],
            'operations.*.client_uuid' => ['required', 'uuid'],
            'operations.*.local_ref' => ['nullable', 'string', 'max:40'],
            'device_code' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:40'],
        ]);

        $results = array_map(fn ($operation) => $runner->run($request->user(), $operation), $payload['operations']);

        $tenant = app(TenantContext::class);
        $batch = SyncBatch::query()->create([
            'pressing_id' => $request->user()->pressing_id,
            'agency_id' => $tenant->agencyId,
            'user_id' => $request->user()->id,
            'reference' => $payload['reference'] ?? ('SYNC-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4))),
            'device_code' => $payload['device_code'] ?? null,
            'operations' => count($payload['operations']),
        ]);
        $runner->recount($batch, $results);

        return $this->ok([
            'batch' => $presenter->batch($batch->load(['agency:id,name', 'user:id,fullname'])),
            'operations' => SyncPresenter::publicResults($results),
            'integrity' => SyncOperationRunner::integrity($results),
        ]);
    }
}
