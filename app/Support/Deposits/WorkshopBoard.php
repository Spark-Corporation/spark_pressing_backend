<?php

namespace App\Support\Deposits;

use App\Actions\Deposit\TransitionWorkshop;
use App\Http\Controllers\Api\V1\Deposits\DepositQuery;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Pressing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Écran Figma « Atelier Kanban » : colonnes, cartes, capacité et aperçu du dépôt.
 */
class WorkshopBoard
{
    public const COLUMN_LABELS = [
        'attente' => 'En attente',
        'cours' => 'En cours',
        'traites' => 'Traités',
        'classes' => 'Classés',
    ];

    public function build(Request $request, ?int $pressingId): array
    {
        $items = $this->query($request)
            ->limit(min(200, (int) $request->get('limit', 100)))
            ->get();
        $stageSince = $this->stageSince($items);

        $grouped = array_fill_keys(array_values(Deposit::etatColumns()), []);
        foreach ($items as $deposit) {
            $colonne = Deposit::etatColumn($deposit->etat) ?? 'attente';
            $grouped[$colonne][] = $this->card($deposit, $stageSince[$deposit->id] ?? $deposit->deposit_date);
        }

        $pressing = $pressingId ? Pressing::query()->find($pressingId) : null;
        $inProgress = count($grouped['cours'] ?? []);
        $capacity = max(1, (int) ($pressing?->workshop_capacity ?? 24));
        $load = (int) round($inProgress / $capacity * 100);
        $total = max(1, $items->count());

        return [
            'capacity' => [
                'in_progress' => $inProgress,
                'capacity' => $capacity,
                'percent' => min(100, $load),
                'label' => match (true) {
                    $load < 50 => 'Charge faible',
                    $load < 85 => 'Charge modérée',
                    default => 'Charge élevée',
                },
            ],
            'workflow' => [
                'laveur_enabled' => (bool) ($pressing?->workflow_laveur_enabled ?? true),
                'classeur_enabled' => (bool) ($pressing?->workflow_classeur_enabled ?? true),
            ],
            'columns' => collect(Deposit::etatColumns())->map(function ($colonne, $etat) use ($grouped, $total) {
                $cards = collect($grouped[$colonne] ?? []);

                return [
                    'etat' => $etat,
                    'colonne' => $colonne,
                    'label' => Deposit::etatLabel($etat),
                    'title' => self::COLUMN_LABELS[$colonne] ?? $colonne,
                    'count' => $cards->count(),
                    'share_percent' => (int) round($cards->count() / $total * 100),
                    'pieces' => (int) $cards->sum('items_count'),
                    'overdue_count' => $cards->where('overdue_minutes', '>', 0)->count(),
                    'next_due_at' => $cards->pluck('echeance')->filter()->sort()->first(),
                    'average_stage_minutes' => $cards->isEmpty() ? null : (int) round($cards->avg('stage_minutes')),
                ];
            })->values(),
            'board' => $grouped,
            'filters' => [
                'services' => [
                    ['code' => 'classique', 'label' => 'Classique'],
                    ['code' => 'express', 'label' => 'Express'],
                    ['code' => 'repassage', 'label' => 'Repassage'],
                ],
                'responsables' => $items->flatMap(fn (Deposit $d) => [$d->laveur, $d->classeur])
                    ->filter()->unique('id')->map(fn ($u) => ['id' => $u->id, 'fullname' => $u->fullname])->values(),
            ],
        ];
    }

    /** Aperçu du dépôt (panneau de droite). */
    public function preview(Deposit $deposit, DepositPresenter $presenter): array
    {
        $deposit->loadMissing(['units', 'client:id,fullname,phone_number,code', 'laveur:id,fullname', 'classeur:id,fullname', 'pressing']);
        $since = $this->stageSince(collect([$deposit]))[$deposit->id] ?? $deposit->deposit_date;
        $detail = $presenter->detail($deposit);
        $allowed = app(TransitionWorkshop::class)->allowedTransitions(
            (bool) $deposit->pressing->workflow_laveur_enabled,
            (bool) $deposit->pressing->workflow_classeur_enabled,
        )[$deposit->etat] ?? [];
        $next = $allowed[0] ?? null;

        return $this->card($deposit, $since) + [
            'articles' => $deposit->units->map(fn ($u) => [
                'quantity' => $u->pricing_type === 'kilo' ? (float) $u->weight_kg : (int) $u->quantity,
                'designation' => $u->designation,
                'note' => collect([$u->color, ...collect($u->defects ?? [])->map(fn ($c) => Deposit::DEFECTS[$c] ?? $c)])
                    ->filter()->implode(' · ') ?: null,
            ])->values(),
            'remarks' => $deposit->notes,
            'responsables' => [
                'laveur' => $deposit->laveur ? ['id' => $deposit->laveur->id, 'fullname' => $deposit->laveur->fullname] : null,
                'classeur' => $deposit->classeur ? ['id' => $deposit->classeur->id, 'fullname' => $deposit->classeur->fullname] : null,
            ],
            'timeline' => $detail['timeline'],
            'next_action' => $next ? [
                'etat' => $next,
                'colonne' => Deposit::etatColumn($next),
                'label' => 'Passer à « '.(self::COLUMN_LABELS[Deposit::etatColumn($next)] ?? Deposit::etatLabel($next)).' »',
            ] : null,
        ];
    }

    private function query(Request $request): Builder
    {
        return Deposit::query()
            ->with([
                'client:id,fullname,phone_number,code',
                'agency:id,name',
                'laveur:id,fullname',
                'classeur:id,fullname',
                'cashier:id,fullname',
            ])
            ->withSum('units as items_count', 'quantity')
            ->tap(fn ($q) => DepositQuery::withListAggregates($q))
            ->withCount(['units as defects_count' => fn ($q) => $q->whereNotNull('defects')])
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->when($request->query('etat'), fn ($q, $etat) => $q->where('etat', $etat))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$term}%")
                ->orWhereHas('client', fn ($c) => $c->where('fullname', 'like', "%{$term}%")->orWhere('phone_number', 'like', "%{$term}%"))))
            ->when($request->query('agency_id'), fn ($q, $id) => $q->where('agency_id', $id))
            ->when($request->query('responsable_id'), fn ($q, $id) => $q->where(fn ($w) => $w->where('laveur_id', $id)->orWhere('classeur_id', $id)))
            ->when($request->query('service'), fn ($q, $service) => match ($service) {
                'express' => $q->where(fn ($w) => $w->where('is_express', true)->orWhereHas('units', fn ($u) => $u->where('type_action', 1))),
                'repassage' => $q->where('is_express', false)->whereDoesntHave('units', fn ($u) => $u->where('type_action', '!=', 2)),
                'classique' => $q->where('is_express', false)->whereHas('units', fn ($u) => $u->where('type_action', 0)),
                default => $q,
            })
            ->when($request->query('due_date'), fn ($q, $date) => $q->whereDate('retrieve_date', $date))
            ->when($request->query('period') && ! $request->query('due_date'), fn ($q) => $q->where('retrieve_date', '<=', match ($request->query('period')) {
                'week' => now()->endOfWeek(),
                'month' => now()->endOfMonth(),
                default => now()->endOfDay(),
            }))
            ->orderByRaw('case when retrieve_date is null then 1 else 0 end')
            ->orderBy('retrieve_date');
    }

    private function card(Deposit $deposit, $since): array
    {
        $responsable = $deposit->laveur ?? $deposit->classeur ?? $deposit->cashier;
        $overdue = $deposit->retrieve_date && $deposit->retrieve_date->isPast()
            ? (int) $deposit->retrieve_date->diffInMinutes(now())
            : 0;
        $priority = $this->priority($deposit, $overdue);

        return array_merge((new DepositResource($deposit))->resolve(), [
            'priority' => $priority,
            'priorite' => $priority['label'],
            'echeance' => $deposit->retrieve_date?->toIso8601String(),
            'overdue_minutes' => $overdue,
            'stage_since' => $since?->toIso8601String(),
            'stage_minutes' => $since ? (int) abs($since->diffInMinutes(now())) : null,
            'elapsed_seconds' => $deposit->deposit_date ? (int) abs(now()->diffInSeconds($deposit->deposit_date)) : null,
            'defects_count' => (int) ($deposit->defects_count ?? 0),
            'responsable' => $responsable ? [
                'id' => $responsable->id,
                'fullname' => $responsable->fullname,
                'initials' => collect(explode(' ', (string) $responsable->fullname))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
            ] : null,
        ]);
    }

    /** @return array{code:string,label:string} */
    private function priority(Deposit $deposit, int $overdueMinutes): array
    {
        return match (true) {
            $overdueMinutes > 0 || ($deposit->is_express && $deposit->retrieve_date?->lte(now()->addHours(2))) => ['code' => 'urgent', 'label' => 'Urgent'],
            $deposit->retrieve_date && $deposit->retrieve_date->lte(now()->addHours(4)) => ['code' => 'high', 'label' => 'Haute'],
            default => ['code' => 'normal', 'label' => 'Normale'],
        };
    }

    /** Date d’entrée dans l’étape courante (dernière transition atelier). */
    private function stageSince(Collection $deposits): array
    {
        if ($deposits->isEmpty()) {
            return [];
        }

        return AuditLog::query()
            ->where('subject_type', Deposit::class)
            ->whereIn('subject_id', $deposits->pluck('id'))
            ->where('action', 'deposit.workshop')
            ->selectRaw('subject_id, max(created_at) as since')
            ->groupBy('subject_id')
            ->pluck('since', 'subject_id')
            ->map(fn ($since) => Carbon::parse($since))
            ->all();
    }
}
