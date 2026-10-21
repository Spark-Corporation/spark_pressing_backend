<?php

namespace App\Support\Licensing;

use App\Models\Agency;
use App\Models\License;
use App\Models\LicensePayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * En-tête du « Centre des licences » : KPI, échéances à traiter, prévision de revenu.
 */
class LicenseOverview
{
    public function build(string $period = 'month'): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->window($period);
        $soonDays = (int) config('licensing.expiring_soon_days');
        $currency = config('licensing.currency');

        $total = License::query()->count();
        $active = License::query()->withStatus('active')->count();
        $expiring = License::query()->withStatus('expiring')->count();
        $suspended = License::query()->withStatus('suspended')->count();
        $criticalUnpaid = License::query()->withStatus('suspended')->where('expires_at', '<', now())->count();

        $renewals = fn ($a, $b) => LicensePayment::query()->where('status', '!=', 'rejected')->whereBetween('created_at', [$a, $b])->count();
        $revenue = fn ($a, $b) => (int) LicensePayment::query()->where('status', 'paid')->whereBetween('paid_at', [$a, $b])->sum(DB::raw('amount + taxes'));

        $renewalsNow = $renewals($from, $to);
        $revenueNow = $revenue($from, $to);

        return [
            'period' => ['code' => $period, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'currency' => $currency,
            'network' => [
                'licenses' => $total,
                'countries' => Agency::query()->whereIn('pressing_id', License::query()->select('pressing_id'))
                    ->distinct()->count('country_code'),
                'updated_at' => now()->toIso8601String(),
            ],
            'kpis' => [
                'active' => [
                    'value' => $active,
                    'share_percent' => $total ? round($active / $total * 100, 1) : 0,
                ],
                'expiring_soon' => ['value' => $expiring, 'within_days' => $soonDays],
                'suspended' => ['value' => $suspended, 'critical_unpaid' => $criticalUnpaid],
                'renewals' => [
                    'value' => $renewalsNow,
                    'delta_percent' => $this->delta($renewalsNow, $renewals($prevFrom, $prevTo)),
                ],
                'revenue' => [
                    'value' => $revenueNow,
                    'currency' => $currency,
                    'delta_percent' => $this->delta($revenueNow, $revenue($prevFrom, $prevTo)),
                ],
            ],
            'deadlines' => $this->deadlines($soonDays),
            'forecast' => $this->forecast(),
        ];
    }

    private function deadlines(int $days): array
    {
        $items = License::query()
            ->with('pressing:id,name')
            ->where('status', true)
            ->where('is_activated', true)
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->orderBy('expires_at')
            ->get();

        return [
            'window_days' => $days,
            'count' => $items->count(),
            'auto_renew_ready' => $items->where('auto_renew', true)->count(),
            'items' => $items->take(10)->map(fn (License $l) => [
                'license_id' => $l->id,
                'reference' => $l->reference,
                'pressing' => ['id' => $l->pressing_id, 'name' => $l->pressing?->name],
                'expires_at' => $l->expires_at->toIso8601String(),
                'days_left' => max(0, (int) ceil(now()->floatDiffInDays($l->expires_at, false))),
                'auto_renew' => $l->auto_renew,
            ])->values()->all(),
        ];
    }

    /** Renouvellements attendus sur le mois courant, valorisés au tarif du plan. */
    private function forecast(): array
    {
        $month = CarbonImmutable::now();
        $licenses = License::query()
            ->where('status', true)
            ->where('is_activated', true)
            ->whereBetween('expires_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->get();

        $amount = 0;
        $secured = 0;
        foreach ($licenses as $license) {
            $price = (int) $license->priceFor($license->periodicity ?: 'annual');
            $amount += $price;
            if ($license->auto_renew) {
                $secured += $price;
            }
        }

        return [
            'month' => $month->format('Y-m'),
            'amount' => $amount,
            'currency' => config('licensing.currency'),
            'renewals_expected' => $licenses->count(),
            'secured_percent' => $amount ? (int) round($secured / $amount * 100) : 0,
        ];
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:CarbonImmutable,3:CarbonImmutable} */
    private function window(string $period): array
    {
        $now = CarbonImmutable::now();
        [$from, $unit] = match ($period) {
            'day' => [$now->startOfDay(), 'day'],
            'week' => [$now->startOfWeek(), 'week'],
            default => [$now->startOfMonth(), 'month'],
        };

        return [$from, $now, $from->sub(1, $unit), $now->sub(1, $unit)];
    }

    private function delta(int $current, int $previous): ?float
    {
        return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
