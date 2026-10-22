<?php

namespace App\Support\Deposits;

use App\Http\Resources\DepositResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Payloads des écrans Figma « Gestion des dépôts », « Détail du dépôt » et « Ticket et facture ».
 */
class DepositPresenter
{
    public const PAYMENT_METHOD_LABELS = [
        'cash' => 'Espèces',
        'card' => 'Carte',
        'mobile_money' => 'Mobile Money',
        'wallet' => 'Portefeuille',
        'other' => 'Autre',
    ];

    private const TIMELINE = [
        'deposit.created' => 'Dépôt enregistré',
        Deposit::ETAT_IN_PROGRESS => 'Pris en charge',
        Deposit::ETAT_TREATED => 'Traitement terminé',
        Deposit::ETAT_CLASSED => 'Prêt pour retrait',
        'deposit.partial_retrieve' => 'Retrait partiel',
        'deposit.retrieved' => 'Retrait effectué',
        'deposit.delivered' => 'Livraison effectuée',
    ];

    /** KPI « Dépôts aujourd’hui », « Chiffre d’affaires », « À retirer aujourd’hui », « Reste à encaisser ». */
    public function kpis(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $sameDayLastWeek = $today->subWeek();

        $todayRow = Deposit::query()
            ->whereBetween('deposit_date', [$today, $today->endOfDay()])
            ->selectRaw('count(*) as cnt, coalesce(sum(total),0) as total')
            ->first();
        $lastWeek = Deposit::query()->whereBetween('deposit_date', [$sameDayLastWeek, $sameDayLastWeek->endOfDay()])->count();
        $collected = (int) Transaction::query()->whereBetween('transaction_date', [$today, $today->endOfDay()])->sum('amount');

        $dueToday = Deposit::query()
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->whereBetween('retrieve_date', [$today, $today->endOfDay()]);
        $open = Deposit::query()->where('status', true)->whereNull('retrieved_at')->where('left_to_pay', '>', 0);

        return [
            'deposits_today' => [
                'value' => (int) $todayRow->cnt,
                'delta_percent' => $lastWeek ? round(((int) $todayRow->cnt - $lastWeek) / $lastWeek * 100, 1) : null,
                'compared_to' => $sameDayLastWeek->toDateString(),
            ],
            'revenue_today' => ['value' => (int) $todayRow->total, 'collected' => $collected],
            'to_retrieve_today' => [
                'value' => (clone $dueToday)->count(),
                'ready' => (clone $dueToday)->whereIn('etat', [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED])->count(),
            ],
            'left_to_collect' => [
                'value' => (int) (clone $open)->sum('left_to_pay'),
                'open_deposits' => (clone $open)->count(),
            ],
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function detail(Deposit $deposit): array
    {
        $deposit->loadMissing([
            'client:id,fullname,phone_number,email,code,loyalty_points,wallet_balance,created_at',
            'agency:id,name,code_prefix,address,contact,email,rccm,tax_id',
            'pressing:id,name,slogan,invoice_terms,express_surcharge_percent,loyalty_redeem_threshold,loyalty_redeem_value,block_retrieve_if_unpaid',
            'units', 'transactions', 'cashier:id,fullname',
        ]);

        $logs = AuditLog::query()
            ->where('subject_type', Deposit::class)
            ->where('subject_id', $deposit->id)
            ->orderBy('id')
            ->get();
        $actors = $this->actors($logs);

        $previousUnpaid = Deposit::query()
            ->where('client_id', $deposit->client_id)
            ->whereKeyNot($deposit->id)
            ->where('status', true)
            ->where('left_to_pay', '>', 0)
            ->selectRaw('count(*) as cnt, coalesce(sum(left_to_pay),0) as amount')
            ->first();

        $data = (new DepositResource($deposit))->resolve();
        $open = ! $deposit->retrieved_at && $deposit->status;

        return $data + [
            'invoice_number' => $deposit->invoiceNumber(),
            'commercial_status' => [
                'code' => $deposit->retrieved_at ? 'closed' : 'open',
                'label' => $deposit->retrieved_at ? 'Clôturé' : 'En cours',
                'retrieve_date' => $deposit->retrieve_date?->toIso8601String(),
            ],
            'workshop_detail' => [
                'status' => $deposit->workshopStatus(),
                'updated_at' => $logs->where('action', 'deposit.workshop')->last()?->created_at?->toIso8601String(),
            ],
            'pricing_mode' => $deposit->units->contains('pricing_type', 'kilo') ? 'kilo' : 'piece',
            'treatment' => [
                'express_hours' => $this->expressHours($deposit),
                'defects_reported' => $deposit->units->contains(fn ($u) => ! empty($u->defects)),
                'renders' => $deposit->units->pluck('render_id')->filter()->unique()->values(),
                'pieces_counted' => (int) $deposit->units->sum('quantity'),
            ],
            'pickup' => [
                'mode' => $deposit->delivery_status ? 'delivery' : 'agency',
                'label' => $deposit->delivery_status ? 'Livraison à domicile' : 'Retrait en agence',
                'agency' => $deposit->agency?->name,
                'retrieve_date' => $deposit->retrieve_date?->toIso8601String(),
                'address' => $deposit->delivery_address,
            ],
            'client_summary' => $deposit->client ? [
                'id' => $deposit->client->id,
                'fullname' => $deposit->client->fullname,
                'phone_number' => $deposit->client->phone_number,
                'client_since' => $deposit->client->created_at?->toDateString(),
                'loyalty_points' => (int) $deposit->client->loyalty_points,
                'previous_unpaid_count' => (int) $previousUnpaid->cnt,
                'previous_unpaid_amount' => (int) $previousUnpaid->amount,
            ] : null,
            'totals' => $this->totals($deposit),
            'payments' => $deposit->transactions->sortBy('transaction_date')->values()->map(fn (Transaction $t) => [
                'id' => $t->id,
                'label' => (int) $t->amount >= (int) $deposit->total ? 'Paiement' : 'Acompte',
                'amount' => (int) $t->amount,
                'payment_method' => ['code' => $t->payment_method, 'label' => self::PAYMENT_METHOD_LABELS[$t->payment_method] ?? $t->payment_method],
                'at' => $t->transaction_date?->toIso8601String(),
                'received_by' => $t->user_id ? User::query()->whereKey($t->user_id)->value('fullname') : null,
            ])->all(),
            'timeline' => $this->timeline($deposit, $logs, $actors),
            'audit' => $logs->reverse()->values()->take(20)->map(fn (AuditLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'actor' => $actors[$log->actor_type.':'.$log->actor_id] ?? 'Système',
                'action' => $log->action,
                'message' => $this->auditMessage($log),
            ])->all(),
            'actions' => [
                'can_print' => true,
                'can_edit' => $open,
                'can_add_payment' => $open && (int) $deposit->left_to_pay > 0,
                'can_cancel' => $open,
                'can_follow_workshop' => $open,
            ],
        ];
    }

    /** Décomposition « Total et paiements » / ticket / facture. */
    public function totals(Deposit $deposit): array
    {
        $deposit->loadMissing('units', 'pressing');
        $loyaltyValue = (int) ($deposit->pressing?->loyalty_redeem_value ?? 0);
        $loyaltyThreshold = max(1, (int) ($deposit->pressing?->loyalty_redeem_threshold ?? 100));
        $pointsDiscount = (int) floor((int) $deposit->points_redeemed / $loyaltyThreshold) * $loyaltyValue;
        $express = (int) $deposit->express_supplement;

        return [
            'currency' => $deposit->currency ?: config('spark.currency'),
            'subtotal' => (int) $deposit->subtotal - $express,
            'express_supplement' => $express,
            'express_percent' => $express ? (int) ($deposit->pressing?->express_surcharge_percent ?? 100) : 0,
            'fees' => (int) $deposit->collection_fee + (int) $deposit->delivery_fee,
            'discount' => max(0, (int) $deposit->discount - $pointsDiscount),
            'points_used' => (int) $deposit->points_redeemed,
            'points_discount' => $pointsDiscount,
            'tax' => 0,
            'total' => (int) $deposit->total,
            'paid' => (int) $deposit->advanced,
            'left_to_pay' => (int) $deposit->left_to_pay,
        ];
    }

    /** Ticket thermique / facture A4 structurés. */
    public function document(Deposit $deposit, string $format): array
    {
        $deposit->load(['agency'])->loadMissing(['client', 'pressing', 'units', 'transactions']);
        $totals = $this->totals($deposit);
        $lines = $deposit->units->map(fn ($u) => [
            'designation' => $u->designation,
            'quantity' => $u->pricing_type === 'kilo' ? (float) $u->weight_kg : (int) $u->quantity,
            'unit' => $u->pricing_type === 'kilo' ? 'kg' : 'pièce',
            'unit_price' => (int) $u->unit_price,
            'total' => (int) $u->line_total - (int) $u->express_supplement,
        ])->values();

        if ($totals['express_supplement'] > 0) {
            $hours = $this->expressHours($deposit);
            $lines->push([
                'designation' => 'Majoration Express'.($hours ? " {$hours} h" : ''),
                'quantity' => 1,
                'unit' => 'forfait',
                'unit_price' => $totals['express_supplement'],
                'total' => $totals['express_supplement'],
            ]);
        }

        return [
            'format' => $format,
            'number' => $format === 'a4' ? $deposit->invoiceNumber() : $deposit->code,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => $deposit->paymentStatus(),
            'seller' => [
                'name' => $deposit->pressing?->name,
                'slogan' => $deposit->pressing?->slogan,
                'agency' => $deposit->agency?->name,
                'address' => $deposit->agency?->address,
                'phone' => $deposit->agency?->contact,
                'email' => $deposit->agency?->email,
                'rccm' => $deposit->agency?->rccm,
                'tax_id' => $deposit->agency?->tax_id,
            ],
            'customer' => [
                'fullname' => $deposit->client?->fullname,
                'phone_number' => $deposit->client?->phone_number,
            ],
            'deposit' => [
                'code' => $deposit->code,
                'date' => $deposit->deposit_date?->toIso8601String(),
                'retrieve_date' => $deposit->retrieve_date?->toIso8601String(),
            ],
            'lines' => $lines->all(),
            'totals' => $totals,
            'payments' => $deposit->transactions->map(fn (Transaction $t) => [
                'method' => self::PAYMENT_METHOD_LABELS[$t->payment_method] ?? $t->payment_method,
                'amount' => (int) $t->amount,
            ])->values()->all(),
            'terms' => $deposit->pressing?->invoice_terms
                ?: 'TVA non applicable — régime simplifié. Paiement reçu en espèces. Le solde est dû au retrait des articles.',
            'qr' => $deposit->qr_token ?: $deposit->code,
            'footer' => $format === 'a4'
                ? 'Merci pour votre confiance. Document généré par SPARK Cloud. Pour suivre votre dépôt, scannez le QR code.'
                : app(SettingsRegistry::class)->value($deposit->pressing_id, 'receipts', 'footer_message'),
        ];
    }

    private function expressHours(Deposit $deposit): ?int
    {
        if (! $deposit->is_express || ! $deposit->retrieve_date || ! $deposit->deposit_date) {
            return null;
        }

        return (int) round(abs($deposit->deposit_date->diffInMinutes($deposit->retrieve_date)) / 60);
    }

    public function auditMessage(AuditLog $log): string
    {
        $p = $log->payload ?? [];

        return match ($log->action) {
            'deposit.created' => 'Dépôt créé'.(! empty($p['terminal']) ? " depuis {$p['terminal']}" : ''),
            'deposit.payment' => sprintf(
                'Paiement %s de %s enregistré',
                mb_strtolower(self::PAYMENT_METHOD_LABELS[$p['payment_method'] ?? ''] ?? ''),
                number_format((int) ($p['amount'] ?? 0), 0, ',', ' ')
            ),
            'deposit.workshop' => 'État atelier passé à « '.Deposit::etatLabel($p['to'] ?? null).' »',
            'deposit.retrieved' => 'Dépôt retiré',
            'deposit.partial_retrieve' => 'Retrait partiel enregistré',
            'deposit.delivered' => 'Livraison confirmée',
            'deposit.deleted' => 'Dépôt annulé',
            default => $log->action,
        };
    }

    private function timeline(Deposit $deposit, Collection $logs, array $actors): array
    {
        $events = [];
        foreach ($logs as $log) {
            $key = $log->action === 'deposit.workshop' ? ($log->payload['to'] ?? null) : $log->action;
            if (! isset(self::TIMELINE[$key])) {
                continue;
            }
            $actor = $actors[$log->actor_type.':'.$log->actor_id] ?? null;
            $events[] = [
                'at' => $log->created_at?->toIso8601String(),
                'code' => $key,
                'title' => self::TIMELINE[$key],
                'detail' => $actor ? "Validé par {$actor}" : null,
            ];
        }

        if ($events === [] || $events[0]['code'] !== 'deposit.created') {
            array_unshift($events, [
                'at' => $deposit->deposit_date?->toIso8601String(),
                'code' => 'deposit.created',
                'title' => self::TIMELINE['deposit.created'],
                'detail' => $deposit->cashier?->fullname,
            ]);
        }

        return array_reverse($events);
    }

    /** @return array<string,string> */
    private function actors(Collection $logs): array
    {
        $names = [];
        foreach ($logs->groupBy('actor_type') as $type => $group) {
            $ids = $group->pluck('actor_id')->filter()->unique();
            $model = match ($type) {
                User::class => User::query(),
                Admin::class => Admin::query(),
                default => null,
            };
            if (! $model) {
                continue;
            }
            foreach ($model->whereIn('id', $ids)->get(['id', 'fullname']) as $actor) {
                $names[$type.':'.$actor->id] = $actor->fullname;
            }
        }

        return $names;
    }
}
