<?php

namespace App\Support\Licensing;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicensePayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Payloads du centre des licences superadmin (Figma 13C — liste + « Détail et renouvellement »).
 */
class LicensePresenter
{
    private const STATUS_LABELS = [
        'active' => 'Active',
        'expiring' => 'À renouveler',
        'expired' => 'Expirée',
        'suspended' => 'Suspendue',
        'inactive' => 'Inactive',
    ];

    private const PAYMENT_STATUS_LABELS = [
        'paid' => 'Payé',
        'pending' => 'En attente',
        'upcoming' => 'À venir',
        'overdue' => 'Impayé',
        'none' => 'Aucun paiement',
    ];

    private const AUDIT_LABELS = [
        'license.created' => 'Création de la licence',
        'license.updated' => 'Modification de la licence',
        'license.plan_changed' => 'Changement de plan',
        'license.activated' => 'Activation de la licence',
        'license.renewed' => 'Préparation du renouvellement',
        'license.payment_validated' => 'Validation du paiement',
        'license.suspended' => 'Suspension de la licence',
        'license.reactivated' => 'Réactivation de la licence',
    ];

    /**
     * Lignes du tableau « Licences multi-pressings ».
     *
     * @param  iterable<License>  $licenses
     */
    public function rows(iterable $licenses): array
    {
        $licenses = collect($licenses);
        $pressingIds = $licenses->pluck('pressing_id')->unique()->all();

        $agencies = Agency::query()->whereIn('pressing_id', $pressingIds)
            ->selectRaw('pressing_id, count(*) as total')->groupBy('pressing_id')->pluck('total', 'pressing_id');
        $users = User::query()->whereIn('pressing_id', $pressingIds)->where('status', true)
            ->selectRaw('pressing_id, count(*) as total')->groupBy('pressing_id')->pluck('total', 'pressing_id');
        $countries = Agency::query()->whereIn('pressing_id', $pressingIds)->orderBy('id')
            ->get(['pressing_id', 'country_code'])->unique('pressing_id')->pluck('country_code', 'pressing_id');

        return $licenses->map(fn (License $license) => $this->row(
            $license,
            (int) ($agencies[$license->pressing_id] ?? 0),
            (int) ($users[$license->pressing_id] ?? 0),
            $countries[$license->pressing_id] ?? null,
        ))->values()->all();
    }

    public function row(License $license, ?int $agencies = null, ?int $users = null, ?string $country = null): array
    {
        $license->loadMissing(['pressing:id,name', 'latestPayment', 'currentPayment']);

        $agencies ??= Agency::query()->where('pressing_id', $license->pressing_id)->count();
        $users ??= User::query()->where('pressing_id', $license->pressing_id)->where('status', true)->count();
        $country ??= Agency::query()->where('pressing_id', $license->pressing_id)->orderBy('id')->value('country_code');

        $period = $this->currentPeriod($license);
        $status = $license->statusCode();

        return [
            'id' => $license->id,
            'reference' => $license->reference,
            'code' => $license->code,
            'pressing_id' => $license->pressing_id,
            'pressing' => [
                'id' => $license->pressing_id,
                'name' => $license->pressing?->name,
                'initials' => $this->initials($license->pressing?->name),
                'country_code' => $country,
                'country' => $this->countryName($country),
            ],
            'plan' => ['code' => $license->plan, 'label' => $license->planLabel()],
            'periodicity' => $this->periodicity($license->periodicity),
            'seats' => $license->seats,
            'is_activated' => $license->is_activated,
            'is_valid' => $license->isValid(),
            'auto_renew' => $license->auto_renew,
            'activated_at' => $license->activated_at?->toIso8601String(),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'days_left' => $period['days_left'],
            'period' => $period,
            'usage' => [
                'agencies' => ['used' => $agencies, 'limit' => $license->agenciesLimit()],
                'users' => ['used' => $users, 'limit' => $license->usersLimit()],
            ],
            'status' => ['code' => $status, 'label' => self::STATUS_LABELS[$status]],
            'payment_status' => $this->paymentStatus($license, $status),
            'suspended_at' => $license->suspended_at?->toIso8601String(),
            'suspension_reason' => $license->suspension_reason,
        ];
    }

    /** Page « Détail et renouvellement ». */
    public function detail(License $license): array
    {
        $license->loadMissing(['pressing:id,name', 'latestPayment']);

        $agencies = Agency::query()->where('pressing_id', $license->pressing_id)->orderBy('id')
            ->get(['id', 'name', 'country_code', 'status']);
        $usersByAgency = User::query()->where('pressing_id', $license->pressing_id)->where('status', true)
            ->selectRaw('agency_id, count(*) as total')->groupBy('agency_id')->pluck('total', 'agency_id');
        $activeUsers = (int) $usersByAgency->sum();

        $row = $this->row($license, $agencies->count(), $activeUsers, $agencies->first()?->country_code);
        $limitAgencies = $license->agenciesLimit();
        $storageUsed = $this->storageUsedGb($license->pressing_id);

        return [
            'license' => $row,
            'renewal' => $this->renewalOptions($license),
            'coverage' => [
                'agencies_count' => $agencies->count(),
                'active_users' => $activeUsers,
                'agencies' => $agencies->values()->map(fn (Agency $agency, int $i) => [
                    'id' => $agency->id,
                    'name' => $agency->name,
                    'users_count' => (int) ($usersByAgency[$agency->id] ?? 0),
                    'covered' => $limitAgencies === null || $i < $limitAgencies,
                ])->all(),
            ],
            'limits' => [
                'plan' => $row['plan'],
                'items' => [
                    $this->limit('agencies', 'Agences', $agencies->count(), $limitAgencies),
                    $this->limit('users', 'Utilisateurs', $activeUsers, $license->usersLimit()),
                    $this->limit('storage', 'Stockage', $storageUsed, $license->storageLimitGb(), 'Go'),
                ],
                'features' => $license->planConfig()['features'] ?? [],
            ],
            'payments' => $license->payments()->latest()->limit(10)->get()
                ->map(fn (LicensePayment $p) => $this->payment($p))->all(),
            'audit' => $this->audit($license),
            'actions' => [
                'can_renew' => true,
                'can_change_plan' => true,
                'can_suspend' => (bool) $license->status,
                'can_reactivate' => ! $license->status,
                'invoice_payment_id' => $license->latestPayment?->id,
            ],
        ];
    }

    public function payment(LicensePayment $payment): array
    {
        $periodicity = $this->periodicity($payment->periodicity);

        return [
            'id' => $payment->id,
            'date' => $payment->created_at?->toIso8601String(),
            'period' => [
                'periodicity' => $periodicity,
                'label' => $periodicity['label'].' · '.$payment->period_end->format('Y'),
                'start' => $payment->period_start->toDateString(),
                'end' => $payment->period_end->toDateString(),
            ],
            'plan' => ['code' => $payment->plan, 'label' => config('licensing.plans.'.$payment->plan.'.label', ucfirst($payment->plan))],
            'amount' => $payment->amount,
            'taxes' => $payment->taxes,
            'total' => $payment->total(),
            'currency' => $payment->currency,
            'payment_method' => [
                'code' => $payment->payment_method,
                'label' => config('licensing.payment_methods.'.$payment->payment_method, $payment->payment_method),
            ],
            'reference' => $payment->reference,
            'invoice_number' => $payment->invoice_number,
            'status' => ['code' => $payment->status, 'label' => LicensePayment::STATUS_LABELS[$payment->status] ?? $payment->status],
            'has_proof' => (bool) $payment->proof_path,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'invoice_url' => url("/api/v1/superadmin/licenses/{$payment->license_id}/payments/{$payment->id}/invoice"),
        ];
    }

    /** Bloc « Renouveler la licence » : offres, moyens de paiement, valeurs par défaut. */
    public function renewalOptions(License $license): array
    {
        $monthly = $license->priceFor('monthly');
        $recommended = config('licensing.recommended_periodicity');
        $taxRate = (float) config('licensing.tax_rate');

        $offers = collect(config('licensing.periodicities'))->map(function (array $p, string $code) use ($license, $monthly, $recommended, $taxRate) {
            $amount = $license->priceFor($code);
            $full = $monthly !== null ? $monthly * $p['months'] : null;
            $saving = ($amount !== null && $full) ? $full - $amount : 0;

            return [
                'periodicity' => $code,
                'label' => $p['label'],
                'months' => $p['months'],
                'days' => $p['days'],
                'amount' => $amount,
                'taxes' => $amount === null ? null : (int) round($amount * $taxRate),
                'total' => $amount === null ? null : $amount + (int) round($amount * $taxRate),
                'savings_percent' => $saving > 0 ? (int) round($saving / $full * 100) : 0,
                'free_months' => ($saving > 0 && $monthly) ? intdiv($saving, $monthly) : 0,
                'recommended' => $code === $recommended,
                'current' => $code === $license->periodicity,
            ];
        })->values()->all();

        $effective = $license->expires_at && $license->expires_at->isFuture()
            ? $license->expires_at->copy()->addDay()->startOfDay()
            : now()->startOfDay();

        return [
            'currency' => config('licensing.currency'),
            'tax_rate' => $taxRate,
            'plan' => ['code' => $license->plan, 'label' => $license->planLabel()],
            'plans' => collect(config('licensing.plans'))->map(fn (array $plan, string $code) => [
                'code' => $code,
                'label' => $plan['label'],
                'prices' => $plan['prices'],
                'limits' => $plan['limits'],
            ])->values()->all(),
            'offers' => $offers,
            'payment_methods' => collect(config('licensing.payment_methods'))
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values()->all(),
            'defaults' => [
                'periodicity' => $license->periodicity ?: $recommended,
                'payment_method' => 'cash',
                'reference' => 'TRX-'.now()->format('Y-m').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'effective_at' => $effective->toDateString(),
                'auto_renew' => $license->auto_renew,
            ],
            'proof' => [
                'accepted' => config('licensing.proof.mimes'),
                'max_size_mb' => (int) (config('licensing.proof.max_kb') / 1024),
            ],
        ];
    }

    /** Période en cours : celle d’un paiement couvrant aujourd’hui, sinon activation → expiration. */
    public function currentPeriod(License $license): array
    {
        $license->loadMissing('currentPayment');
        $covering = $license->currentPayment;

        $start = $covering?->period_start ?? $license->period_started_at ?? $license->activated_at;
        $end = $covering?->period_end ?? $license->expires_at;

        if (! $start || ! $end) {
            return ['start' => $start?->toDateString(), 'end' => $end?->toDateString(), 'days_total' => null, 'days_left' => $end ? $this->daysUntil($end) : null, 'consumed_percent' => null];
        }

        $total = max(1, (int) round($start->floatDiffInDays($end)));
        $left = $this->daysUntil($license->expires_at ?? $end);
        $leftInPeriod = min($total, $this->daysUntil($end));

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days_total' => $total,
            'days_left' => $left,
            'consumed_percent' => (int) round(($total - $leftInPeriod) / $total * 100),
        ];
    }

    private function paymentStatus(License $license, string $status): array
    {
        $latest = $license->latestPayment;

        $code = match (true) {
            $latest?->status === 'pending' => 'pending',
            $status === 'expired' => 'overdue',
            $status === 'expiring' && (! $latest || ! $latest->period_end->gt($license->expires_at)) => 'upcoming',
            $latest?->status === 'paid' => 'paid',
            default => 'none',
        };

        return ['code' => $code, 'label' => self::PAYMENT_STATUS_LABELS[$code]];
    }

    private function audit(License $license): array
    {
        $last = AuditLog::query()
            ->where('subject_type', License::class)
            ->where('subject_id', $license->id)
            ->latest('id')
            ->first();

        $payment = $license->latestPayment;
        $checks = [];
        if ($payment) {
            if ($payment->reference) {
                $checks[] = ['code' => 'reference', 'label' => "Référence {$payment->reference} enregistrée", 'ok' => true];
            }
            $checks[] = match (true) {
                ! $payment->proof_path => ['code' => 'proof', 'label' => 'Aucun justificatif joint', 'ok' => false],
                $payment->status === 'paid' => ['code' => 'proof', 'label' => 'Justificatif validé', 'ok' => true],
                $payment->status === 'rejected' => ['code' => 'proof', 'label' => 'Justificatif rejeté', 'ok' => false],
                default => ['code' => 'proof', 'label' => 'Justificatif en attente de validation', 'ok' => true],
            };
        }

        $status = $license->statusCode();
        $compliance = match (true) {
            $status === 'expired' || $payment?->status === 'rejected' => ['code' => 'non_compliant', 'label' => 'Non conforme'],
            $status === 'suspended' => ['code' => 'warning', 'label' => 'À surveiller'],
            default => ['code' => 'compliant', 'label' => 'Conforme'],
        };

        return [
            'status' => $compliance,
            'last_action' => $last ? [
                'action' => $last->action,
                'label' => self::AUDIT_LABELS[$last->action] ?? $last->action,
                'actor' => $this->actorName($last),
                'at' => $last->created_at?->toIso8601String(),
            ] : null,
            'checks' => $checks,
        ];
    }

    private function actorName(AuditLog $log): ?string
    {
        $model = match ($log->actor_type) {
            Admin::class => Admin::query()->find($log->actor_id),
            User::class => User::query()->find($log->actor_id),
            default => null,
        };

        return $model?->fullname ?? $model?->name ?? null;
    }

    private function limit(string $key, string $label, int|float $used, ?int $limit, ?string $unit = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'used' => $used,
            'limit' => $limit,
            'unit' => $unit,
            'percent' => $limit ? (int) min(100, round($used / $limit * 100)) : null,
        ];
    }

    private function periodicity(?string $code): array
    {
        return ['code' => $code, 'label' => config('licensing.periodicities.'.$code.'.label', ucfirst((string) $code))];
    }

    private function daysUntil(Carbon $date): int
    {
        return max(0, (int) ceil(now()->floatDiffInDays($date, false)));
    }

    private function storageUsedGb(int $pressingId): float
    {
        $disk = Storage::disk(config('filesystems.default'));
        $bytes = collect($disk->allFiles("pressings/{$pressingId}"))->sum(fn (string $f) => $disk->size($f));

        return round($bytes / 1024 ** 3, 2);
    }

    private function initials(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        return Collection::make(preg_split('/\s+/', trim($name)))
            ->take(2)
            ->map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->implode('');
    }

    private function countryName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        return class_exists(\Locale::class)
            ? (\Locale::getDisplayRegion('-'.$code, 'fr') ?: $code)
            : $code;
    }
}
