<?php

namespace App\Support\Deposits;

use App\Http\Controllers\Api\V1\Deposits\DepositQuery;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\Pressing;
use App\Models\User;
use App\Support\Sms\SmsSender;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Écrans Figma « Centre de retrait » et « Traitement d’un retrait ».
 */
class RetrievalPresenter
{
    private const READY = [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED];

    public static function readyQuery(): Builder
    {
        return Deposit::query()
            ->where('deposits.status', true)
            ->whereNull('deposits.retrieved_at')
            ->whereIn('deposits.etat', self::READY);
    }

    public function kpis(): array
    {
        $today = CarbonImmutable::now();
        $readyToday = self::readyQuery()->where(fn ($q) => $q->whereNull('retrieve_date')->orWhere('retrieve_date', '<=', $today->endOfDay()));
        $toNotify = self::readyQuery()->whereNull('deposits.notified_at');
        $channels = (clone $toNotify)
            ->join('clients', 'clients.id', '=', 'deposits.client_id')
            ->selectRaw("coalesce(clients.contact_preference, 'sms') as channel, count(*) as total")
            ->groupBy('channel')
            ->pluck('total', 'channel');
        $retrieved = fn ($day) => Deposit::query()->whereBetween('retrieved_at', [$day->startOfDay(), $day->endOfDay()])->count();
        $retrievedToday = $retrieved($today);
        $lastWeek = $retrieved($today->subWeek());
        $unpaid = self::readyQuery()->where('left_to_pay', '>', 0);

        return [
            'ready_today' => [
                'value' => (clone $readyToday)->count(),
                'pieces' => (int) (clone $readyToday)->withSum('units as pieces', 'quantity')->get()->sum('pieces'),
            ],
            'awaiting_notification' => [
                'value' => (clone $toNotify)->count(),
                'by_channel' => ['sms' => (int) ($channels['sms'] ?? 0), 'whatsapp' => (int) ($channels['whatsapp'] ?? 0)],
            ],
            'retrieved_today' => [
                'value' => $retrievedToday,
                'delta_percent' => $lastWeek ? round(($retrievedToday - $lastWeek) / $lastWeek * 100, 1) : null,
                'compared_to' => $today->subWeek()->toDateString(),
            ],
            'unpaid_balances' => [
                'value' => (int) (clone $unpaid)->sum('left_to_pay'),
                'deposits' => (clone $unpaid)->count(),
            ],
        ];
    }

    /** « Retraits du jour » — prochains rendez-vous. */
    public function schedule(int $limit = 6): array
    {
        return Deposit::query()
            ->with('client:id,fullname')
            ->withSum('units as items_count', 'quantity')
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->whereBetween('retrieve_date', [now(), now()->endOfDay()])
            ->orderBy('retrieve_date')
            ->limit($limit)
            ->get()
            ->map(fn (Deposit $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'time' => $d->retrieve_date?->toIso8601String(),
                'client' => $d->client?->fullname,
                'pieces' => (int) $d->items_count,
                'payment' => (int) $d->left_to_pay > 0
                    ? ['code' => 'to_collect', 'label' => 'À encaisser']
                    : ['code' => 'settled', 'label' => 'Soldé'],
            ])->all();
    }

    public function readyList(Request $request)
    {
        return self::readyQuery()
            ->select('deposits.*')
            ->with(['client:id,fullname,phone_number,code,contact_preference', 'agency:id,name'])
            ->withSum('units as items_count', 'quantity')
            ->tap(fn ($q) => DepositQuery::withListAggregates($q))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$term}%")
                ->orWhere('qr_token', $term)
                ->orWhereHas('client', fn ($c) => $c->where('fullname', 'like', "%{$term}%")->orWhere('phone_number', 'like', "%{$term}%"))))
            ->when($request->query('agency_id'), fn ($q, $id) => $q->where('deposits.agency_id', $id))
            ->when($request->query('date'), fn ($q, $date) => $q->whereDate('retrieve_date', '<=', $date))
            ->when($request->query('payment_status') === 'unpaid', fn ($q) => $q->where('left_to_pay', '>', 0))
            ->when($request->query('payment_status') === 'paid', fn ($q) => $q->where('left_to_pay', 0))
            ->when($request->query('etat'), fn ($q, $etat) => $q->where('etat', $etat))
            ->orderByRaw('case when retrieve_date is null then 1 else 0 end')
            ->orderBy('retrieve_date')
            ->orderBy('deposit_date');
    }

    public function row(Deposit $deposit): array
    {
        return (new DepositResource($deposit))->resolve() + [
            'notification' => $deposit->notificationState(),
        ];
    }

    /** « Vêtements classés depuis 30+ jours ». */
    public function aged(): array
    {
        $deposits = self::readyQuery()
            ->whereNull('aged_handled_at')
            ->with('client:id,fullname,phone_number')
            ->withSum('units as items_count', 'quantity')
            ->limit(1000)
            ->get();
        $readyAt = AuditLog::query()
            ->where('subject_type', Deposit::class)
            ->whereIn('subject_id', $deposits->pluck('id'))
            ->where('action', 'deposit.workshop')
            ->selectRaw('subject_id, max(created_at) as at')
            ->groupBy('subject_id')
            ->pluck('at', 'subject_id');

        $items = $deposits->map(function (Deposit $d) use ($readyAt) {
            $since = isset($readyAt[$d->id]) ? Carbon::parse($readyAt[$d->id]) : ($d->retrieve_date ?? $d->deposit_date);
            $days = (int) floor(abs($since->diffInDays(now())));

            return [
                'id' => $d->id,
                'code' => $d->code,
                'client' => $d->client?->fullname,
                'phone_number' => $d->client?->phone_number,
                'ready_at' => $since->toIso8601String(),
                'retrieve_date' => $d->retrieve_date?->toIso8601String(),
                'waiting_days' => $days,
                'pieces' => (int) $d->items_count,
                'value' => (int) $d->total,
                'storage_fee' => (int) $d->storage_fee,
                'decision' => $this->decision($d, $days),
            ];
        })->filter(fn ($i) => $i['waiting_days'] >= 30)->sortByDesc('waiting_days')->values();

        $bucket = fn ($min, $max) => $items->filter(fn ($i) => $i['waiting_days'] >= $min && ($max === null || $i['waiting_days'] <= $max));

        return [
            'to_handle' => $items->count(),
            'buckets' => collect(['30_60' => [30, 60], '61_90' => [61, 90], '90_plus' => [91, null]])
                ->map(fn ($range) => [
                    'count' => $bucket(...$range)->count(),
                    'pieces' => (int) $bucket(...$range)->sum('pieces'),
                    'value' => (int) $bucket(...$range)->sum('value'),
                ])->all(),
            'items' => $items->all(),
        ];
    }

    /** « Traitement d’un retrait » — préparation. */
    public function checkout(Deposit $deposit, ?User $user, DepositPresenter $presenter): array
    {
        $detail = $presenter->detail($deposit);
        $deposit->loadMissing('client', 'agency');
        $blocking = (bool) Pressing::query()->whereKey($deposit->pressing_id)->value('block_retrieve_if_unpaid');
        $blocked = $blocking && (int) $deposit->left_to_pay > 0;

        return [
            'deposit' => $detail,
            'header' => [
                'client' => $detail['client_summary'],
                'received_by' => $deposit->cashier?->fullname,
                'order_label' => sprintf('%s · %s', $detail['quantity_label'] ?? '—', $detail['service']['label']),
                'badges' => array_values(array_filter([
                    $deposit->etat === Deposit::ETAT_CLASSED || $deposit->etat === Deposit::ETAT_TREATED
                        ? ['code' => 'workshop_done', 'label' => 'Atelier terminé'] : null,
                    (int) $deposit->left_to_pay > 0
                        ? ['code' => 'due', 'label' => number_format((int) $deposit->left_to_pay, 0, ',', ' ').' FCFA dus'] : null,
                ])),
            ],
            'steps' => $this->steps($deposit, $detail['timeline']),
            'units' => $deposit->units->map(fn ($u) => [
                'id' => $u->id,
                'designation' => $u->designation,
                'note' => collect([$u->color, ...collect($u->defects ?? [])->map(fn ($c) => Deposit::DEFECTS[$c] ?? $c)])->filter()->implode(' · ') ?: null,
                'quantity' => (int) $u->quantity,
                'retrieved' => (int) $u->retrieve_quantity,
                'remaining' => max(0, (int) $u->quantity - (int) $u->retrieve_quantity),
                'verification' => [
                    'code' => $u->verification ?? 'compliant',
                    'label' => ['compliant' => 'Conforme', 'control_required' => 'Contrôle requis'][$u->verification ?? 'compliant'],
                ],
            ])->values(),
            'balance' => [
                'total' => (int) $deposit->total,
                'paid' => (int) $deposit->advanced,
                'left_to_pay' => (int) $deposit->left_to_pay,
                'blocked' => $blocked,
                'block_reason' => $blocked ? 'Retrait bloqué : solde impayé' : null,
                'can_override' => (bool) $user?->can('deposits.override'),
            ],
            'receiver' => [
                'types' => [['code' => 'client', 'label' => 'Client lui-même'], ['code' => 'third_party', 'label' => 'Tiers autorisé']],
                'default_name' => $deposit->client?->fullname,
            ],
            'conditions' => [
                ['code' => 'compliant', 'label' => 'Conforme'],
                ['code' => 'client_reserve', 'label' => 'Réserve client'],
                ['code' => 'anomaly', 'label' => 'Anomalie constatée'],
            ],
            'after_confirm' => [
                'print_slip' => ['enabled' => true, 'terminal' => $deposit->terminal],
                'notify_sms' => ['enabled' => (bool) $deposit->client?->phone_number, 'destination' => SmsSender::mask($deposit->client?->phone_number)],
            ],
            'audit' => $detail['audit'],
        ];
    }

    /** Étapes « Lavage → … → Remis » avec date et opérateur. */
    private function steps(Deposit $deposit, array $timeline): array
    {
        $byCode = collect($timeline)->keyBy('code');
        $order = [
            Deposit::ETAT_IN_PROGRESS => 'Pris en charge',
            Deposit::ETAT_TREATED => 'Traité',
            Deposit::ETAT_CLASSED => 'Prêt',
            'deposit.retrieved' => 'Remis',
        ];
        $reached = true;

        return collect($order)->map(function ($label, $code) use ($byCode, &$reached, $deposit) {
            $event = $byCode->get($code);
            $done = $event !== null || ($code !== 'deposit.retrieved' && $this->etatRank($deposit->etat) >= $this->etatRank($code));
            $state = $done ? 'done' : ($reached ? 'current' : 'pending');
            if (! $done) {
                $reached = false;
            }

            return [
                'code' => $code,
                'label' => $label,
                'state' => $state,
                'at' => $event['at'] ?? null,
                'by' => isset($event['detail']) ? str_replace('Validé par ', '', (string) $event['detail']) : null,
            ];
        })->values()->all();
    }

    private function etatRank(?string $etat): int
    {
        return array_search($etat, [Deposit::ETAT_WAITING, Deposit::ETAT_IN_PROGRESS, Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED], true) ?: 0;
    }

    /** @return array{code:string,label:string} */
    private function decision(Deposit $deposit, int $days): array
    {
        return match (true) {
            (bool) $deposit->escalated_at => ['code' => 'escalated', 'label' => 'Escaladé'],
            $days > 90 => ['code' => 'escalate', 'label' => 'Escalade'],
            $deposit->notified_at && $deposit->notified_at->gt(now()->subDays(7)) => ['code' => 'storage_fee', 'label' => 'Frais de garde'],
            default => ['code' => 'remind', 'label' => 'Relancer'],
        };
    }

    public static function notificationMessage(Deposit $deposit): string
    {
        $client = $deposit->client ?? Client::query()->find($deposit->client_id);

        return sprintf(
            'Bonjour %s, votre dépôt %s est prêt au retrait.%s',
            $client?->fullname ?? '',
            $deposit->code,
            (int) $deposit->left_to_pay > 0 ? ' Reste à payer : '.number_format((int) $deposit->left_to_pay, 0, ',', ' ').' FCFA.' : ''
        );
    }
}
