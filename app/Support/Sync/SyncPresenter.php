<?php

namespace App\Support\Sync;

use App\Models\Agency;
use App\Models\Deposit;
use App\Models\PosDevice;
use App\Models\SyncBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Écran Figma « 12 — Synchronisation » : santé du réseau, lots et appareils hors ligne. */
class SyncPresenter
{
    private const ONLINE_MINUTES = 30;

    public function center(int $pressingId, ?int $agencyId = null, int $days = 30): array
    {
        $since = now()->subDays($days);
        $agencies = Agency::query()->where('pressing_id', $pressingId)->where('status', true)->orderBy('name')->get();
        $batches = SyncBatch::query()->where('pressing_id', $pressingId)->where('created_at', '>=', $since)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->with(['agency:id,name', 'user:id,fullname'])->latest('id')->get();
        $lastDeposit = Deposit::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)
            ->selectRaw('agency_id, max(updated_at) as at')->groupBy('agency_id')->pluck('at', 'agency_id');

        $agencyRows = $agencies->map(function (Agency $a) use ($batches, $lastDeposit) {
            $mine = $batches->where('agency_id', $a->id);
            $lastBatch = $mine->sortByDesc('created_at')->first();
            $lastActivity = collect([$lastBatch?->created_at, $lastDeposit[$a->id] ?? null])->filter()
                ->map(fn ($d) => CarbonImmutable::parse($d))->max();
            $online = $lastActivity !== null && $lastActivity->gt(now()->subMinutes(self::ONLINE_MINUTES));
            $pending = (int) $mine->where('status', '!=', 'completed')->sum(fn (SyncBatch $b) => $b->failed + max(0, $b->operations - $b->succeeded - $b->conflicts - $b->failed));

            return [
                'agency_id' => $a->id,
                'name' => $a->name,
                'online' => $online,
                'status' => $online ? 'online' : 'offline',
                'status_label' => $online ? 'En ligne · synchronisée' : 'Hors ligne',
                'last_sync_at' => $lastActivity?->toIso8601String(),
                'last_sync_ago' => $lastActivity?->locale('fr')->diffForHumans(),
                'pending_operations' => $pending,
                'interruptions' => $mine->where('status', 'failed')->count(),
                'batches' => $mine->count(),
                'availability_percent' => $mine->count()
                    ? round($mine->where('status', '!=', 'failed')->count() / $mine->count() * 100, 1)
                    : 100.0,
            ];
        });

        $filtered = $agencyId ? $agencyRows->where('agency_id', $agencyId)->values() : $agencyRows;
        $total = $batches->count();
        $pendingOps = (int) $filtered->sum('pending_operations');

        return [
            'period' => ['days' => $days, 'from' => $since->toIso8601String(), 'to' => now()->toIso8601String()],
            'agency_id' => $agencyId,
            'kpis' => [
                'availability' => [
                    'percent' => $total ? round($batches->where('status', '!=', 'failed')->count() / $total * 100, 1) : 100.0,
                    'batches' => $total,
                ],
                'online_agencies' => ['value' => $agencyRows->where('online', true)->count(), 'total' => $agencies->count()],
                'pending_operations' => ['value' => $pendingOps],
                'failed_batches' => ['value' => $batches->where('status', 'failed')->count()],
            ],
            'agencies' => $filtered->values()->all(),
            'batches' => $batches->take(50)->map(fn (SyncBatch $b) => $this->batch($b))->values()->all(),
            'devices' => $this->devices($pressingId, $agencyId),
            'alerts' => $this->alerts($filtered),
        ];
    }

    public function batch(SyncBatch $b): array
    {
        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'agency' => $b->agency ? ['id' => $b->agency->id, 'name' => $b->agency->name] : null,
            'user' => $b->user?->fullname,
            'device_code' => $b->device_code,
            'operations' => $b->operations,
            'succeeded' => $b->succeeded,
            'conflicts' => $b->conflicts,
            'failed' => $b->failed,
            'status' => $b->status,
            'status_label' => match ($b->status) {
                'completed' => 'Terminé',
                'partial' => 'Partiel',
                'failed' => 'Échec',
                default => $b->status,
            },
            'created_at' => $b->created_at?->toIso8601String(),
            'ago' => $b->created_at?->locale('fr')->diffForHumans(),
        ];
    }

    /** Statut POS courant pour l’agence active. */
    public function status(int $pressingId, ?int $agencyId, ?string $deviceCode = null): array
    {
        $last = SyncBatch::query()->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when($deviceCode, fn ($q) => $q->where('device_code', $deviceCode))
            ->latest('id')->first();
        $pending = SyncBatch::query()->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->where('status', '!=', 'completed')
            ->sum('failed');

        return [
            'online' => true,
            'last_sync_at' => $last?->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'pending_ops' => (int) $pending,
            'pending' => [],
            'last_batch' => $last ? $this->batch($last->loadMissing(['agency:id,name', 'user:id,fullname'])) : null,
        ];
    }

    private function devices(int $pressingId, ?int $agencyId): array
    {
        return PosDevice::query()->withoutGlobalScopes()->with(['agency:id,name', 'responsible:id,fullname'])
            ->where('pressing_id', $pressingId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->orderBy('code')->get()
            ->map(fn (PosDevice $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'agency' => $d->agency?->name,
                'responsible' => $d->responsible?->fullname,
                'status' => $d->effectiveStatus(),
                'status_label' => PosDevice::STATUS_LABELS[$d->effectiveStatus()] ?? $d->effectiveStatus(),
                'offline_enabled' => (bool) $d->offline_enabled,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ])->all();
    }

    private function alerts(Collection $agencies): array
    {
        return $agencies->filter(fn ($a) => ! $a['online'] || $a['pending_operations'] > 0 || $a['interruptions'] > 0)
            ->map(fn ($a) => [
                'agency_id' => $a['agency_id'],
                'severity' => $a['pending_operations'] > 0 || $a['interruptions'] > 2 ? 'warning' : 'info',
                'title' => $a['pending_operations'] > 0
                    ? "{$a['name']} · {$a['pending_operations']} opérations en attente"
                    : ($a['online'] ? "{$a['name']} · interruptions récentes" : "{$a['name']} · hors ligne"),
                'detail' => $a['last_sync_ago'] ? 'Dernière synchro '.$a['last_sync_ago'] : 'Aucune synchronisation',
                'endpoint' => '/api/v1/sync/center?agency_id='.$a['agency_id'],
            ])->values()->all();
    }
}
