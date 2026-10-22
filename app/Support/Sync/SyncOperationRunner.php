<?php

namespace App\Support\Sync;

use App\Actions\Deposit\AddPayment;
use App\Actions\Deposit\CreateDeposit;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Promo;
use App\Models\SyncBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Rejoue une opération hors ligne (Figma 02e « Retour en ligne et synchronisation ») :
 * dépôt synchronisé (LOCAL → code serveur), doublon UUID, encaissement rapproché,
 * conflit « décision requise » (code promo désactivé) ou échec.
 */
class SyncOperationRunner
{
    public const STATUS_LABELS = [
        'synced' => 'Synchronisé',
        'reconciled' => 'Rapproché',
        'duplicate' => 'Déjà synchronisé',
        'conflict' => 'Décision requise',
        'held' => 'En attente de décision responsable',
        'failed' => 'Échec',
    ];

    public const CONFLICT_OPTIONS = [
        ['code' => 'keep_discount', 'label' => 'Conserver la remise', 'detail' => 'Exception auditée, validation responsable'],
        ['code' => 'remove_discount', 'label' => 'Retirer la remise', 'detail' => 'Le montant de la remise reste à régulariser'],
        ['code' => 'hold', 'label' => 'Mettre en attente', 'detail' => 'Décision du responsable'],
    ];

    public function __construct(private readonly CreateDeposit $create, private readonly AddPayment $pay) {}

    public function run(User $user, array $operation): array
    {
        $base = [
            'type' => $operation['type'],
            'client_uuid' => $operation['client_uuid'],
            'local_ref' => $operation['local_ref'] ?? null,
        ];
        $payload = $operation['payload'] + ['client_uuid' => $operation['client_uuid']];

        try {
            return DB::transaction(function () use ($user, $operation, $payload, $base) {
                if ($operation['type'] === 'deposit') {
                    $deposit = $this->create->handle($user, $payload);
                    $status = $deposit->wasRecentlyCreated ? 'synced' : 'duplicate';

                    return $base + ['status' => $status, 'status_label' => self::STATUS_LABELS[$status], 'id' => $deposit->id, 'server_ref' => $deposit->code,
                        'amount' => (int) $deposit->advanced, 'renumbered' => $status === 'synced' && ! empty($base['local_ref'])];
                }

                $deposit = Deposit::query()->findOrFail($payload['deposit_id'] ?? null);
                $this->pay->handle($user, $deposit, $payload);

                return $base + ['status' => 'reconciled', 'status_label' => self::STATUS_LABELS['reconciled'], 'id' => $deposit->id, 'server_ref' => $deposit->code, 'amount' => (int) ($payload['amount'] ?? 0)];
            });
        } catch (ValidationException $e) {
            if (isset($e->errors()['promo_code'])) {
                $promo = Promo::query()->where('code', $payload['promo_code'] ?? '')->first();

                return $base + [
                    'status' => 'conflict',
                    'status_label' => self::STATUS_LABELS['conflict'],
                    'conflict' => [
                        'code' => 'promo_unavailable',
                        'message' => 'Code promo '.($payload['promo_code'] ?? '').' : '.$e->errors()['promo_code'][0],
                        'promo_code' => $payload['promo_code'] ?? null,
                        'promo_percent' => $promo?->rate_percent,
                        'options' => self::CONFLICT_OPTIONS,
                    ],
                    'payload' => $operation['payload'],
                ];
            }

            return $base + ['status' => 'failed', 'status_label' => self::STATUS_LABELS['failed'], 'error' => collect($e->errors())->flatten()->first(), 'payload' => $operation['payload']];
        } catch (Throwable $e) {
            return $base + ['status' => 'failed', 'status_label' => self::STATUS_LABELS['failed'], 'error' => $e->getMessage(), 'payload' => $operation['payload']];
        }
    }

    /** « Appliquer et reprendre » sur une opération en conflit d’un lot. */
    public function resolve(User $user, SyncBatch $batch, string $clientUuid, string $decision): array
    {
        $results = $batch->results ?? [];
        $index = collect($results)->search(fn ($r) => ($r['client_uuid'] ?? null) === $clientUuid);
        if ($index === false || ! in_array($results[$index]['status'], ['conflict', 'held'], true)) {
            throw ValidationException::withMessages(['client_uuid' => 'Aucune décision n’est attendue pour cette opération.']);
        }
        $current = $results[$index];
        if ($decision === 'keep_discount' && ! $user->can('deposits.override')) {
            throw ValidationException::withMessages(['decision' => 'Conserver la remise nécessite un responsable habilité.']);
        }

        if ($decision === 'hold') {
            $results[$index] = ['status' => 'held', 'status_label' => self::STATUS_LABELS['held']] + $current;
        } else {
            $payload = $current['payload'];
            $promoCode = $payload['promo_code'] ?? null;
            unset($payload['promo_code']);
            if ($decision === 'keep_discount' && ! empty($current['conflict']['promo_percent'])) {
                $payload['discount_percent'] = (int) $current['conflict']['promo_percent'];
                $payload['discount_reason'] = "Exception synchronisation : code {$promoCode} conservé";
            }
            $result = $this->run($user, ['type' => $current['type'], 'client_uuid' => $clientUuid, 'local_ref' => $current['local_ref'] ?? null, 'payload' => $payload]);
            $results[$index] = $result + ['decision' => $decision];
            AuditLog::record('sync.conflict_resolved', $batch, ['client_uuid' => $clientUuid, 'decision' => $decision, 'promo_code' => $promoCode]);
        }

        $this->recount($batch, $results);

        return $results[$index];
    }

    public function recount(SyncBatch $batch, array $results): void
    {
        $by = collect($results)->countBy('status');
        $ok = ($by['synced'] ?? 0) + ($by['reconciled'] ?? 0) + ($by['duplicate'] ?? 0);
        $conflicts = ($by['conflict'] ?? 0) + ($by['held'] ?? 0);
        $failed = $by['failed'] ?? 0;
        $batch->fill([
            'results' => array_values($results),
            'succeeded' => $ok,
            'conflicts' => $conflicts,
            'failed' => $failed,
            'status' => match (true) {
                $failed === 0 && $conflicts === 0 => 'completed',
                $ok === 0 && $conflicts === 0 => 'failed',
                default => 'partial',
            },
        ])->save();
    }

    /** « Contrôles d’intégrité » : déduplication UUID, renumérotation LOCAL → serveur, encaissements rapprochés. */
    public static function integrity(array $results): array
    {
        $r = collect($results);

        return [
            'deduplication' => ['duplicates' => $r->where('status', 'duplicate')->count(), 'status' => 'validated'],
            'renumbering' => ['done' => $r->where('renumbered', true)->count(), 'total' => $r->filter(fn ($x) => ! empty($x['local_ref']) && $x['type'] === 'deposit')->count()],
            'reconciled_payments' => ['count' => $r->where('status', 'reconciled')->count(), 'amount' => (int) $r->whereIn('status', ['reconciled', 'synced'])->sum('amount')],
        ];
    }
}
