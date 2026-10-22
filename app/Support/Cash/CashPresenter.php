<?php

namespace App\Support\Cash;

use App\Models\Agency;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Deposits\DepositPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Écrans Figma « Centre de caisse et journal », « Nouveau mouvement » et « Clôture et rapprochement ».
 * Montants sur l’agence d’opération (contexte tenant) et la journée demandée.
 */
class CashPresenter
{
    public const METHODS = ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'card' => 'Carte / Virement'];

    public function session(int $agencyId, CarbonImmutable $day): ?CashSession
    {
        return CashSession::query()
            ->with(['opener:id,fullname', 'counter:id,fullname', 'controller:id,fullname'])
            ->where('agency_id', $agencyId)
            ->whereDate('business_date', $day->toDateString())
            ->first();
    }

    public function center(int $agencyId, CarbonImmutable $day): array
    {
        $session = $this->session($agencyId, $day);
        $figures = $this->figures($agencyId, $day, $session);
        $prev = $this->figures($agencyId, $day->subWeek(), null);
        $unpaid = Deposit::query()->where('status', true)->where('left_to_pay', '>', 0);
        $received = $figures['deposit_receipts']['amount'] + $figures['balance_payments']['amount'];

        return [
            'date' => $day->toDateString(),
            'agency' => Agency::query()->whereKey($agencyId)->value('name'),
            'session' => $this->sessionPayload($session),
            'kpis' => [
                'receipts' => [
                    'value' => $received,
                    'delta_percent' => ($p = $prev['deposit_receipts']['amount'] + $prev['balance_payments']['amount'])
                        ? round(($received - $p) / $p * 100, 1) : null,
                ],
                'expenses' => ['value' => $figures['cash_out']['amount'], 'count' => $figures['cash_out']['count']],
                'unpaid' => ['value' => (int) (clone $unpaid)->sum('left_to_pay'), 'deposits' => (clone $unpaid)->count()],
                'difference' => ['value' => $figures['difference'], 'label' => $figures['counted'] === null ? 'Comptage non saisi' : ($figures['difference'] === 0 ? 'Comptage conforme' : 'Écart à justifier')],
            ],
            'situation' => [
                'opening' => [
                    'amount' => (int) ($session?->opening_balance ?? 0),
                    'at' => $session?->opened_at?->toIso8601String(),
                    'by' => $session?->opener?->fullname,
                ],
                'deposit_receipts' => $figures['deposit_receipts'],
                'balance_payments' => $figures['balance_payments'],
                'cash_in' => $figures['cash_in'],
                'cash_out' => $figures['cash_out'],
            ],
            'theoretical' => ['amount' => $figures['theoretical'], 'computed_at' => now()->toIso8601String()],
            'counted' => ['amount' => $figures['counted'], 'difference' => $figures['difference']],
            'breakdown' => [
                'total' => $received,
                'methods' => collect(self::METHODS)->map(fn ($label, $code) => [
                    'code' => $code,
                    'label' => $label,
                    'amount' => $figures['receipts_by_method'][$code] ?? 0,
                    'percent' => $received ? round(($figures['receipts_by_method'][$code] ?? 0) / $received * 100, 1) : 0,
                ])->values()->all(),
            ],
            'hourly' => $this->hourly($agencyId, $day),
            'journal_totals' => [
                'operations' => $figures['operations'],
                'total_in' => $received + $figures['cash_in']['amount'],
                'total_out' => $figures['cash_out']['amount'],
            ],
        ];
    }

    /** Journal unifié encaissements + mouvements. */
    public function journal(int $agencyId, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        [$from, $to] = $this->range($filters);
        $flow = $filters['flow'] ?? null;
        $q = $filters['q'] ?? null;

        $rows = collect();
        if (! in_array($filters['status'] ?? null, ['validated', 'pending_approval', 'to_validate'], true) && $flow !== 'out' && empty($filters['category_id'])) {
            $rows = $rows->concat(Transaction::query()
                ->leftJoin('deposits', 'deposits.id', '=', 'transactions.deposit_id')
                ->leftJoin('users', 'users.id', '=', 'transactions.user_id')
                ->where('transactions.agency_id', $agencyId)
                ->whereBetween('transactions.transaction_date', [$from, $to])
                ->when($filters['payment_method'] ?? null, fn ($w, $m) => $w->where('transactions.payment_method', $m))
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('deposits.code', 'like', "%{$q}%")->orWhere('users.fullname', 'like', "%{$q}%")))
                ->get(['transactions.*', 'deposits.code as deposit_code', 'deposits.deposit_date as deposit_date', 'users.fullname as operator'])
                ->map(fn ($t) => $this->transactionRow($t)));
        }
        if (($filters['status'] ?? null) !== 'synced') {
            $rows = $rows->concat(CashMovement::query()
                ->with(['category:id,name', 'operator:id,fullname'])
                ->where('agency_id', $agencyId)
                ->whereBetween('action_date', [$from, $to])
                ->when($flow, fn ($w) => $w->where('type', $flow))
                ->when($filters['category_id'] ?? null, fn ($w, $c) => $w->where('category_id', $c))
                ->when($filters['payment_method'] ?? null, fn ($w, $m) => $w->where('payment_method', $m))
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('label', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%")->orWhere('beneficiary', 'like', "%{$q}%")))
                ->get()
                ->map(fn (CashMovement $m) => $this->movementRow($m))
                ->filter(fn ($r) => empty($filters['status']) || $r['status']['code'] === $filters['status']));
        }

        $rows = $rows->sortByDesc('at')->values();

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);
    }

    public function movementRow(CashMovement $m): array
    {
        return [
            'id' => 'mvt-'.$m->id,
            'source' => 'movement',
            'source_id' => $m->id,
            'at' => $m->action_date?->toIso8601String(),
            'reference' => sprintf('MVT-%05d', $m->id),
            'category' => $m->category?->name ?? $m->label,
            'label' => $m->label,
            'operator' => $m->operator?->fullname,
            'payment_method' => ['code' => $m->payment_method, 'label' => DepositPresenter::PAYMENT_METHOD_LABELS[$m->payment_method] ?? $m->payment_method],
            'flow' => ['code' => $m->type, 'label' => $m->type === 'out' ? 'Sortie' : 'Entrée'],
            'amount' => (int) $m->amount,
            'status' => $m->workflowStatus(),
        ];
    }

    /** Clôture : synthèse, checklist, rapprochement par moyen, opérateurs, anomalies. */
    public function closing(CashSession $session): array
    {
        $day = CarbonImmutable::parse($session->business_date);
        $figures = $this->figures($session->agency_id, $day, $session);
        $counted = $session->counted ?? [];
        $pending = CashMovement::query()->where('agency_id', $session->agency_id)->whereDate('action_date', $day)->where('requires_approval', true)->where('validated', false)->count();

        $reconciliation = collect(self::METHODS)->map(function ($label, $code) use ($figures, $counted) {
            $theoretical = $figures['theoretical_by_method'][$code] ?? 0;
            $real = $counted[$code] ?? null;

            return [
                'code' => $code,
                'label' => $label,
                'theoretical' => $theoretical,
                'counted' => $real,
                'difference' => $real === null ? null : (int) $real - $theoretical,
                'status' => $real === null
                    ? ['code' => 'pending', 'label' => 'À saisir']
                    : ((int) $real === $theoretical ? ['code' => 'compliant', 'label' => 'Conforme'] : ['code' => 'gap', 'label' => 'Écart']),
            ];
        })->values();

        $checklist = [
            ['code' => 'journal', 'label' => 'Journal vérifié', 'detail' => "{$figures['operations']} opérations contrôlées", 'done' => $session->counted !== null],
            ['code' => 'cash', 'label' => 'Espèces recomptées', 'detail' => isset($counted['cash']) ? number_format((int) $counted['cash'], 0, ',', ' ').' FCFA' : null, 'done' => isset($counted['cash'])],
            ['code' => 'mobile_money', 'label' => 'Relevés Mobile Money', 'detail' => null, 'done' => isset($counted['mobile_money'])],
            ['code' => 'card', 'label' => 'Paiements carte vérifiés', 'detail' => null, 'done' => isset($counted['card'])],
            ['code' => 'anomalies', 'label' => 'Anomalies traitées', 'detail' => $pending ? "{$pending} mouvement(s) en attente" : 'Aucune anomalie bloquante', 'done' => $pending === 0 && ($figures['difference'] === 0 || filled($session->difference_reason))],
            ['code' => 'double_control', 'label' => 'Double contrôle effectué', 'detail' => collect([$session->counter?->fullname, $session->controller?->fullname])->filter()->implode(' · ') ?: null, 'done' => (bool) $session->controlled_by],
        ];

        return [
            'session' => $this->sessionPayload($session),
            'status' => $session->status === 'closed'
                ? ['code' => 'closed', 'label' => 'Clôturée']
                : (collect($checklist)->every('done') ? ['code' => 'ready', 'label' => 'Prête à clôturer'] : ['code' => 'in_progress', 'label' => 'En cours']),
            'kpis' => [
                'receipts' => ['value' => $figures['deposit_receipts']['amount'] + $figures['balance_payments']['amount'], 'count' => $figures['deposit_receipts']['count'] + $figures['balance_payments']['count']],
                'expenses' => ['value' => $figures['cash_out']['amount'], 'count' => $figures['cash_out']['count']],
                'theoretical' => ['value' => $figures['theoretical']],
                'difference' => ['value' => $figures['difference']],
            ],
            'summary' => [
                'from' => $session->opened_at?->toIso8601String(),
                'opening' => (int) $session->opening_balance,
                'deposit_receipts' => $figures['deposit_receipts'],
                'balance_payments' => $figures['balance_payments'],
                'cash_in' => $figures['cash_in'],
                'cash_out' => $figures['cash_out'],
                'theoretical' => $figures['theoretical'],
            ],
            'reconciliation' => $reconciliation->all(),
            'result' => [
                'compliant' => $figures['counted'] !== null && $figures['difference'] === 0,
                'label' => $figures['counted'] === null ? 'Comptage à saisir' : ($figures['difference'] === 0 ? 'Caisse conforme · écart zéro' : 'Écart à justifier'),
                'theoretical' => $figures['theoretical'],
                'counted' => $figures['counted'],
                'difference' => $figures['difference'],
                'reason' => $session->difference_reason,
                'counted_by' => $session->counter?->fullname,
                'counted_at' => $session->counted_at?->toIso8601String(),
                'controlled_by' => $session->controller?->fullname,
                'controlled_at' => $session->controlled_at?->toIso8601String(),
            ],
            'checklist' => ['total' => count($checklist), 'done' => collect($checklist)->where('done', true)->count(), 'items' => $checklist],
            'operators' => $this->operators($session, $day),
            'anomalies' => [
                'unsynced_operations' => 0,
                'unresolved' => $figures['counted'] !== null && $figures['difference'] !== 0 && blank($session->difference_reason) ? 1 : 0,
                'pending_approvals' => $pending,
            ],
            'report' => [
                'filename' => sprintf('Cloture_%s_%s.pdf', strtoupper((string) Agency::query()->whereKey($session->agency_id)->value('code_prefix')), $day->toDateString()),
                'url' => route('v1.cash.sessions.report', ['cashSession' => $session->id]),
            ],
        ];
    }

    public function sessionPayload(?CashSession $session): ?array
    {
        if (! $session) {
            return null;
        }

        return [
            'id' => $session->id,
            'code' => $session->code,
            'business_date' => $session->business_date?->toDateString(),
            'status' => $session->status === 'open' ? ['code' => 'open', 'label' => 'Caisse ouverte'] : ['code' => 'closed', 'label' => 'Caisse clôturée'],
            'opened_at' => $session->opened_at?->toIso8601String(),
            'opened_by' => $session->opener?->fullname,
            'opening_balance' => (int) $session->opening_balance,
            'closed_at' => $session->closed_at?->toIso8601String(),
        ];
    }

    /** Solde espèces disponible (pour « Caisse concernée » du nouveau mouvement). */
    public function availableCash(int $agencyId, CarbonImmutable $day): int
    {
        $figures = $this->figures($agencyId, $day, $this->session($agencyId, $day));

        return $figures['theoretical_by_method']['cash'];
    }

    private function figures(int $agencyId, CarbonImmutable $day, ?CashSession $session): array
    {
        $from = $day->startOfDay();
        $to = $day->endOfDay();

        $tx = Transaction::query()
            ->leftJoin('deposits', 'deposits.id', '=', 'transactions.deposit_id')
            ->where('transactions.agency_id', $agencyId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->get(['transactions.amount', 'transactions.payment_method', 'transactions.transaction_date', 'deposits.deposit_date']);
        [$atDeposit, $balances] = $tx->partition(fn ($t) => $t->deposit_date && Carbon::parse($t->deposit_date)->equalTo(Carbon::parse($t->transaction_date)));

        $movements = CashMovement::query()
            ->where('agency_id', $agencyId)
            ->where('validated', true)
            ->whereBetween('action_date', [$from, $to])
            ->get(['type', 'amount', 'payment_method']);
        $in = $movements->where('type', 'in');
        $out = $movements->where('type', 'out');

        $byMethod = fn (Collection $rows, string $method) => (int) $rows->filter(fn ($r) => $this->bucket($r->payment_method) === $method)->sum('amount');
        $receiptsByMethod = collect(array_keys(self::METHODS))->mapWithKeys(fn ($m) => [$m => $byMethod($tx, $m)])->all();
        $opening = (int) ($session?->opening_balance ?? 0);
        $theoreticalByMethod = collect(array_keys(self::METHODS))->mapWithKeys(fn ($m) => [
            $m => ($m === 'cash' ? $opening : 0) + $receiptsByMethod[$m] + $byMethod($in, $m) - $byMethod($out, $m),
        ])->all();
        $theoretical = array_sum($theoreticalByMethod);
        $counted = $session?->counted ? (int) array_sum($session->counted) : null;

        return [
            'deposit_receipts' => ['amount' => (int) $atDeposit->sum('amount'), 'count' => $atDeposit->count()],
            'balance_payments' => ['amount' => (int) $balances->sum('amount'), 'count' => $balances->count()],
            'cash_in' => ['amount' => (int) $in->sum('amount'), 'count' => $in->count()],
            'cash_out' => ['amount' => (int) $out->sum('amount'), 'count' => $out->count()],
            'receipts_by_method' => $receiptsByMethod,
            'theoretical_by_method' => $theoreticalByMethod,
            'theoretical' => $theoretical,
            'counted' => $counted,
            'difference' => $counted === null ? 0 : $counted - $theoretical,
            'operations' => $tx->count() + CashMovement::query()->where('agency_id', $agencyId)->whereBetween('action_date', [$from, $to])->count(),
        ];
    }

    /** « Flux de caisse » par tranche de 60 minutes (7 h → 18 h). */
    private function hourly(int $agencyId, CarbonImmutable $day): array
    {
        $tx = Transaction::query()->where('agency_id', $agencyId)->whereBetween('transaction_date', [$day->startOfDay(), $day->endOfDay()])->get(['amount', 'transaction_date']);
        $mv = CashMovement::query()->where('agency_id', $agencyId)->where('validated', true)->whereBetween('action_date', [$day->startOfDay(), $day->endOfDay()])->get(['type', 'amount', 'action_date']);

        $series = [];
        for ($h = 7; $h <= 18; $h++) {
            $inHour = fn ($date) => (int) Carbon::parse($date)->format('G') === $h;
            $series[] = [
                'hour' => sprintf('%02dh', $h),
                'in' => (int) $tx->filter(fn ($t) => $inHour($t->transaction_date))->sum('amount') + (int) $mv->where('type', 'in')->filter(fn ($m) => $inHour($m->action_date))->sum('amount'),
                'out' => (int) $mv->where('type', 'out')->filter(fn ($m) => $inHour($m->action_date))->sum('amount'),
            ];
        }

        return [
            'series' => $series,
            'net' => array_sum(array_column($series, 'in')) - array_sum(array_column($series, 'out')),
        ];
    }

    private function operators(CashSession $session, CarbonImmutable $day): array
    {
        $counts = Transaction::query()->where('agency_id', $session->agency_id)->whereDate('transaction_date', $day)
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        foreach (CashMovement::query()->where('agency_id', $session->agency_id)->whereDate('action_date', $day)
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id') as $id => $n) {
            $counts[$id] = ($counts[$id] ?? 0) + $n;
        }

        $roles = [];
        foreach (['opened_by' => 'Ouverture', 'counted_by' => 'Comptage', 'controlled_by' => 'Contrôle'] as $field => $label) {
            if ($session->{$field}) {
                $roles[$session->{$field}][] = $label;
                $counts[$session->{$field}] = $counts[$session->{$field}] ?? 0;
            }
        }

        return User::query()->whereIn('id', collect($counts)->keys()->filter())->get(['id', 'fullname'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'fullname' => $u->fullname,
                'roles' => $roles[$u->id] ?? [],
                'operations' => (int) ($counts[$u->id] ?? 0),
            ])->sortByDesc('operations')->values()->all();
    }

    private function transactionRow($t): array
    {
        $atDeposit = $t->deposit_date && Carbon::parse($t->deposit_date)->equalTo(Carbon::parse($t->transaction_date));

        return [
            'id' => 'tx-'.$t->id,
            'source' => 'transaction',
            'source_id' => $t->id,
            'at' => Carbon::parse($t->transaction_date)->toIso8601String(),
            'reference' => $atDeposit ? (string) $t->deposit_code : sprintf('SOL-%05d', $t->id),
            'category' => $atDeposit ? 'Dépôt' : 'Solde dépôt',
            'label' => $t->deposit_code,
            'operator' => $t->operator,
            'payment_method' => ['code' => $t->payment_method, 'label' => DepositPresenter::PAYMENT_METHOD_LABELS[$t->payment_method] ?? $t->payment_method],
            'flow' => ['code' => 'in', 'label' => 'Entrée'],
            'amount' => (int) $t->amount,
            'status' => ['code' => 'synced', 'label' => 'Synchronisé'],
        ];
    }

    private function bucket(?string $method): string
    {
        return match ($method) {
            'mobile_money' => 'mobile_money',
            'card', 'other' => 'card',
            default => 'cash',
        };
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(array $filters): array
    {
        $day = CarbonImmutable::parse($filters['date'] ?? now());

        return match ($filters['period'] ?? 'day') {
            'week' => [$day->startOfWeek(), $day->endOfWeek()],
            'month' => [$day->startOfMonth(), $day->endOfMonth()],
            default => [$day->startOfDay(), $day->endOfDay()],
        };
    }
}
