<?php

namespace App\Support\Reporting;

use App\Http\Controllers\Api\V1\Deposits\DepositQuery;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\User;
use App\Support\Deposits\DepositPresenter;
use Illuminate\Support\Collection;

/**
 * Bloc « Activité récente » du dashboard : journal d’audit rendu lisible
 * (titre, sous-titre, montant signé ou statut).
 */
class ActivityFeed
{
    private const ACTIONS = [
        'deposit.created', 'deposit.payment', 'deposit.retrieved', 'deposit.partial_retrieve',
        'deposit.workshop', 'deposit.delivered', 'cash.movement', 'cash.validated', 'client.created',
        'client.points_converted',
    ];

    public function latest(int $limit = 15): array
    {
        $logs = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->latest('id')
            ->limit($limit)
            ->get();

        $deposits = Deposit::query()
            ->with('client:id,fullname')
            ->withSum('units as items_count', 'quantity')
            ->tap(fn ($q) => DepositQuery::withListAggregates($q))
            ->whereIn('id', $logs->where('subject_type', Deposit::class)->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $movements = CashMovement::query()
            ->whereIn('id', $logs->where('subject_type', CashMovement::class)->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $clients = Client::query()
            ->whereIn('id', $logs->where('subject_type', Client::class)->pluck('subject_id'))
            ->get(['id', 'fullname', 'loyalty_tier', 'loyalty_points'])
            ->keyBy('id');
        $actors = User::query()
            ->whereIn('id', $logs->where('actor_type', User::class)->pluck('actor_id'))
            ->pluck('fullname', 'id');

        return $logs->map(fn (AuditLog $log) => $this->item($log, $deposits, $movements, $clients, $actors))
            ->filter()
            ->values()
            ->all();
    }

    private function item(AuditLog $log, Collection $deposits, Collection $movements, Collection $clients, Collection $actors): ?array
    {
        $p = $log->payload ?? [];
        $deposit = $log->subject_type === Deposit::class ? $deposits->get($log->subject_id) : null;
        $client = $deposit?->client?->fullname;
        $base = [
            'id' => $log->id,
            'type' => $log->action,
            'agency_id' => $log->agency_id,
            'subject_type' => class_basename((string) $log->subject_type),
            'subject_id' => $log->subject_id,
            'at' => $log->created_at?->toIso8601String(),
        ];

        $row = match ($log->action) {
            'deposit.created' => $deposit ? [
                'kind' => 'deposit',
                'title' => "Dépôt enregistré · {$deposit->code}",
                'subtitle' => collect([$client, $this->quantity($deposit), $this->service($deposit)])->filter()->implode(' · '),
                'amount' => (int) $deposit->total,
                'amount_type' => 'credit',
            ] : null,
            'deposit.payment' => $deposit ? [
                'kind' => 'payment',
                'title' => "Paiement reçu · {$deposit->code}",
                'subtitle' => collect([$client, $this->method($p['payment_method'] ?? null)])->filter()->implode(' · paiement '),
                'amount' => (int) ($p['amount'] ?? 0),
                'amount_type' => 'credit',
            ] : null,
            'deposit.retrieved', 'deposit.partial_retrieve' => $deposit ? [
                'kind' => 'retrieve',
                'title' => ($log->action === 'deposit.retrieved' ? 'Retrait finalisé' : 'Retrait partiel').' · '.$deposit->code,
                'subtitle' => collect([$client, $deposit->payment_method ? 'paiement '.$this->method($deposit->payment_method) : null])->filter()->implode(' · '),
                'amount' => (int) $deposit->advanced,
                'amount_type' => 'credit',
            ] : null,
            'deposit.delivered' => $deposit ? [
                'kind' => 'delivery',
                'title' => "Livraison effectuée · {$deposit->code}",
                'subtitle' => $client,
                'amount' => null,
                'amount_type' => 'status',
                'status_label' => 'Livré',
            ] : null,
            'deposit.workshop' => $deposit ? [
                'kind' => 'workshop',
                'title' => 'Commande passée à l’étape « '.Deposit::etatLabel($p['to'] ?? null).' »',
                'subtitle' => collect([
                    $deposit->code,
                    $this->quantity($deposit),
                    ($name = $actors->get($log->actor_id)) ? "Opérateur : {$name}" : null,
                ])->filter()->implode(' · '),
                'amount' => null,
                'amount_type' => 'status',
                'status_label' => Deposit::etatLabel($p['to'] ?? null),
            ] : null,
            'cash.movement', 'cash.validated' => ($m = $movements->get($log->subject_id)) ? [
                'kind' => 'cash',
                'title' => ($m->type === 'out' ? 'Dépense de caisse' : 'Entrée de caisse').($log->action === 'cash.validated' ? ' validée' : ' saisie'),
                'subtitle' => $m->label,
                'amount' => (int) $m->amount,
                'amount_type' => $m->type === 'out' ? 'debit' : 'credit',
            ] : null,
            'client.created' => ($c = $clients->get($log->subject_id)) ? [
                'kind' => 'client',
                'title' => 'Nouveau client',
                'subtitle' => $c->fullname.' · niveau '.$c->tier()['label'],
                'amount' => null,
                'amount_type' => 'status',
                'status_label' => 'Créé',
            ] : null,
            'client.points_converted' => ($c = $clients->get($log->subject_id)) ? [
                'kind' => 'loyalty',
                'title' => 'Points convertis en remise',
                'subtitle' => $c->fullname,
                'amount' => -(int) ($p['points'] ?? 0),
                'amount_type' => 'points',
            ] : null,
            default => null,
        };

        return $row ? $base + $row : null;
    }

    private function quantity(Deposit $deposit): ?string
    {
        return DepositResource::quantityLabel((int) ($deposit->pieces_count ?? 0), (float) ($deposit->weight_total ?? 0));
    }

    private function service(Deposit $deposit): string
    {
        return Deposit::serviceBadge((bool) $deposit->is_express, isset($deposit->min_type_action) ? (int) $deposit->min_type_action : null, isset($deposit->max_type_action) ? (int) $deposit->max_type_action : null)['label'];
    }

    private function method(?string $method): ?string
    {
        return $method ? (DepositPresenter::PAYMENT_METHOD_LABELS[$method] ?? $method) : null;
    }
}
