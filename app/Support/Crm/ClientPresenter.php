<?php

namespace App\Support\Crm;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\Pressing;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payloads des écrans Figma « CRM clients » et « Fiche client ».
 */
class ClientPresenter
{
    /** Expression SQL du palier (forcé ou déduit des points). */
    public static function tierSql(): string
    {
        $cases = collect(config('spark.loyalty_tiers'))
            ->sortByDesc('min_points')
            ->map(fn ($tier, $code) => "when clients.loyalty_points >= {$tier['min_points']} then '{$code}'")
            ->implode(' ');

        return "coalesce(clients.loyalty_tier, case {$cases} end)";
    }

    public static function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['tier'] ?? null, fn ($q, $tier) => $q->whereRaw(self::tierSql().' = ?', [$tier]))
            ->when($filters['crm_status'] ?? null, fn ($q, $status) => match ($status) {
                'archived' => $q->whereNotNull('archived_at'),
                'inactive' => $q->whereNull('archived_at')->where('status', false),
                'vip' => $q->whereNull('archived_at')->where('status', true)->whereRaw(self::tierSql().' = ?', [config('spark.vip_tier')]),
                'active' => $q->whereNull('archived_at')->where('status', true),
                default => $q,
            })
            ->when($filters['agency_id'] ?? null, fn ($q, $id) => $q->where('clients.agency_id', $id))
            ->when($filters['period'] ?? null, fn ($q, $period) => $q->where('last_deposit_at', '>=', match ($period) {
                'week' => now()->startOfWeek(),
                'month' => now()->startOfMonth(),
                default => now()->startOfDay(),
            }));
    }

    public static function withRowAggregates(Builder $query): Builder
    {
        return $query
            ->withCount('deposits')
            ->withSum('deposits as lifetime_value', 'total')
            ->withMin('deposits as first_deposit_at', 'deposit_date');
    }

    /** KPI + répartition fidélité + compteurs des puces de palier. */
    public function overview(): array
    {
        $monthStart = now()->startOfMonth();
        $active = fn () => Client::query()->whereNull('archived_at')->where('status', true);

        $activeCount = $active()->count();
        $newThisMonth = Client::query()->where('created_at', '>=', $monthStart)->count();
        $activeBeforeMonth = $active()->where('created_at', '<', $monthStart)->count();

        $tiers = $active()
            ->selectRaw(self::tierSql().' as tier, count(*) as total')
            ->groupBy('tier')
            ->pluck('total', 'tier');
        $allTiers = Client::query()
            ->whereNull('archived_at')
            ->selectRaw(self::tierSql().' as tier, count(*) as total')
            ->groupBy('tier')
            ->pluck('total', 'tier');

        $earned = fn ($from, $to) => (int) LoyaltyLedger::query()->where('type', 'earn')->whereBetween('created_at', [$from, $to])->sum('points');
        $earnedThisMonth = $earned($monthStart, now());
        $earnedLastMonth = $earned($monthStart->copy()->subMonth(), $monthStart->copy()->subSecond());
        $vip = (int) ($tiers[config('spark.vip_tier')] ?? 0);

        return [
            'kpis' => [
                'active_clients' => [
                    'value' => $activeCount,
                    'delta_percent_month' => $activeBeforeMonth ? round(($activeCount - $activeBeforeMonth) / $activeBeforeMonth * 100, 1) : null,
                ],
                'new_clients' => [
                    'value' => $newThisMonth,
                    'this_week' => Client::query()->where('created_at', '>=', now()->startOfWeek())->count(),
                ],
                'vip_clients' => [
                    'value' => $vip,
                    'share_percent' => $activeCount ? round($vip / $activeCount * 100, 1) : 0,
                ],
                'points' => [
                    'value' => (int) $active()->sum('loyalty_points'),
                    'earned_this_month' => $earnedThisMonth,
                    'delta_percent_month' => $earnedLastMonth ? round(($earnedThisMonth - $earnedLastMonth) / $earnedLastMonth * 100, 1) : null,
                ],
            ],
            'loyalty_distribution' => [
                'total' => $activeCount,
                'tiers' => collect(config('spark.loyalty_tiers'))->map(fn ($tier, $code) => [
                    'code' => $code,
                    'label' => $tier['label'],
                    'count' => (int) ($tiers[$code] ?? 0),
                    'percent' => $activeCount ? (int) round(($tiers[$code] ?? 0) / $activeCount * 100) : 0,
                ])->values()->all(),
            ],
            'tier_filters' => collect(config('spark.loyalty_tiers'))
                ->map(fn ($tier, $code) => ['code' => $code, 'label' => $tier['label'], 'count' => (int) ($allTiers[$code] ?? 0)])
                ->prepend(['code' => null, 'label' => 'Tous', 'count' => (int) $allTiers->sum()])
                ->values()->all(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /** Ligne du « Répertoire clients ». */
    public function row(Client $client): array
    {
        $deposits = (int) ($client->deposits_count ?? 0);

        return (new ClientResource($client))->resolve() + [
            'initials' => self::initials($client->fullname),
            'last_deposit_at' => $client->last_deposit_at?->toIso8601String(),
            'frequency_per_month' => self::frequency($deposits, $client->first_deposit_at ?? null),
            'lifetime_value' => (int) ($client->lifetime_value ?? 0),
            'tier' => $client->tier(),
            'crm_status' => $client->crmStatus(),
        ];
    }

    /** « Fiche client ». */
    public function profile(Client $client): array
    {
        $client->loadMissing('agency:id,name', 'pressing:id,loyalty_redeem_threshold,loyalty_redeem_value');
        $stats = Deposit::query()
            ->where('client_id', $client->id)
            ->selectRaw('count(*) as cnt, coalesce(sum(total),0) as total, min(deposit_date) as first_at')
            ->first();
        $deposits = (int) $stats->cnt;
        $sixMonths = CarbonImmutable::now()->subMonths(6);
        $avg = fn ($from, $to) => (float) Deposit::query()->where('client_id', $client->id)->whereBetween('deposit_date', [$from, $to])->avg('total');
        $avgRecent = $avg($sixMonths, now());
        $avgBefore = $avg($sixMonths->subMonths(6), $sixMonths);
        $due = Deposit::query()->where('client_id', $client->id)->where('status', true)->whereNull('retrieved_at')->sum('left_to_pay');

        return array_merge($this->row($client), [
            'phone_secondary' => $client->fixe_number,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'contact_preference' => $client->contact_preference
                ? ['code' => $client->contact_preference, 'label' => Client::CONTACT_PREFERENCES[$client->contact_preference] ?? $client->contact_preference]
                : null,
            'stats' => [
                'lifetime_value' => (int) $stats->total,
                'client_since' => $client->created_at?->toDateString(),
                'deposits' => $deposits,
                'frequency_per_month' => self::frequency($deposits, $stats->first_at),
                'average_basket' => $deposits ? (int) round($stats->total / $deposits) : 0,
                'average_basket_delta_percent_6m' => $avgBefore > 0 ? round(($avgRecent - $avgBefore) / $avgBefore * 100, 1) : null,
                'balance_due' => (int) $due,
            ],
            'loyalty' => $this->loyalty($client),
            'points_history' => LoyaltyLedger::query()
                ->leftJoin('deposits', 'deposits.id', '=', 'loyalty_points_ledger.deposit_id')
                ->where('loyalty_points_ledger.client_id', $client->id)
                ->latest('loyalty_points_ledger.id')
                ->limit(6)
                ->get(['loyalty_points_ledger.*', 'deposits.code as deposit_code'])
                ->map(fn ($l) => [
                    'label' => $l->type === 'redeem' ? 'Remise fidélité' : ($l->deposit_code ? 'Dépôt' : ($l->reason ?: 'Bonus')),
                    'reason' => $l->reason,
                    'reference' => $l->deposit_code,
                    'points' => (int) $l->points,
                    'at' => $l->created_at?->toIso8601String(),
                ])->all(),
            'timeline' => $this->timeline($client),
            'recent_orders' => Deposit::query()
                ->where('client_id', $client->id)
                ->withSum('units as items_count', 'quantity')
                ->latest('deposit_date')
                ->limit(4)
                ->get()
                ->map(fn (Deposit $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'date' => $d->deposit_date?->toIso8601String(),
                    'articles' => (int) $d->items_count,
                    'total' => (int) $d->total,
                    'status' => $this->orderStatus($d),
                ])->all(),
            'preferences' => [
                'contact_channel' => $client->contact_preference ? (Client::CONTACT_PREFERENCES[$client->contact_preference] ?? null) : null,
                'pickup_slot' => $client->pickup_slot,
                'favorite_treatment' => $client->favorite_treatment,
                'instructions' => $client->instructions,
            ],
            'notes' => [
                'count' => $client->notes()->count(),
                'items' => $client->notes()->with('author:id,fullname')->limit(5)->get()->map(fn (ClientNote $n) => $this->note($n))->all(),
            ],
            'account' => [
                'client_since' => $client->created_at?->toDateString(),
                'agency' => $client->agency?->name,
                'marketing_consent' => $client->consent_sms || $client->consent_email,
                'consents' => [
                    'sms' => (bool) $client->consent_sms,
                    'email' => (bool) $client->consent_email,
                    'updated_at' => $client->consent_updated_at?->toIso8601String(),
                ],
                'archived_at' => $client->archived_at?->toIso8601String(),
            ],
        ]);
    }

    public function loyalty(Client $client): array
    {
        $pressing = $client->pressing ?? Pressing::query()->find($client->pressing_id);
        $threshold = max(1, (int) ($pressing?->loyalty_redeem_threshold ?? 100));
        $value = (int) ($pressing?->loyalty_redeem_value ?? 0);
        $points = (int) $client->loyalty_points;
        $tiers = config('spark.loyalty_tiers');
        $current = $client->tier();
        $next = collect($tiers)->first(fn ($tier) => $tier['min_points'] > $points);
        $nextCode = $next ? array_search($next, $tiers, true) : null;

        return [
            'points' => $points,
            'value' => (int) floor($points * $value / $threshold),
            'tier' => $current,
            'next_tier' => $next ? ['code' => $nextCode, 'label' => $next['label'], 'min_points' => $next['min_points']] : null,
            'points_to_next_tier' => $next ? $next['min_points'] - $points : 0,
            'progress_percent' => $next ? (int) min(100, round($points / $next['min_points'] * 100)) : 100,
            'conversion' => [
                'points' => $threshold,
                'value' => $value,
                'minimum_points' => (int) app(SettingsRegistry::class)->value($client->pressing_id, 'loyalty', 'program.minimum_points', $threshold),
            ],
        ];
    }

    public function note(ClientNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->author?->fullname,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    private function timeline(Client $client): array
    {
        $deposits = Deposit::query()
            ->where('client_id', $client->id)
            ->withSum('units as items_count', 'quantity')
            ->latest('deposit_date')
            ->limit(6)
            ->get();

        $events = [];
        foreach ($deposits as $d) {
            $events[] = [
                'type' => 'deposit',
                'title' => 'Dépôt enregistré',
                'detail' => sprintf('%d articles · %s FCFA', (int) $d->items_count, number_format((int) $d->total, 0, ',', ' ')),
                'reference' => $d->code,
                'at' => $d->deposit_date?->toIso8601String(),
            ];
            if ($d->retrieved_at) {
                $events[] = [
                    'type' => 'retrieve',
                    'title' => 'Retrait effectué',
                    'detail' => sprintf('%d articles remis', (int) $d->items_count),
                    'reference' => $d->code,
                    'at' => $d->retrieved_at->toIso8601String(),
                ];
            }
        }

        return collect($events)->sortByDesc('at')->take(6)->values()->all();
    }

    private function orderStatus(Deposit $d): array
    {
        return match (true) {
            (bool) $d->retrieved_at => ['code' => 'retrieved', 'label' => 'Retirée'],
            in_array($d->etat, [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED], true) => ['code' => 'ready', 'label' => 'Prête au retrait'],
            default => ['code' => 'in_progress', 'label' => 'En cours'],
        };
    }

    private static function frequency(int $deposits, $firstAt): float
    {
        if ($deposits === 0 || ! $firstAt) {
            return 0.0;
        }

        $months = max(1, CarbonImmutable::parse($firstAt)->floatDiffInMonths(now()));

        return round($deposits / $months, 1);
    }

    public static function initials(?string $name): string
    {
        return collect(preg_split('/\s+/', trim((string) $name)))
            ->filter()
            ->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->implode('');
    }
}
