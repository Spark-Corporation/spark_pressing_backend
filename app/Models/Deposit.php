<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deposit extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** « État du vêtement » — défauts constatés à la réception. */
    public const DEFECTS = [
        'stain' => 'Taché',
        'torn' => 'Déchiré',
        'broken_button' => 'Bouton cassé',
        'hole' => 'Troué',
        'faded' => 'Décoloré',
        'other' => 'Autre',
    ];

    public const ETAT_WAITING = 'waiting';

    public const ETAT_IN_PROGRESS = 'in_progress';

    public const ETAT_TREATED = 'treated';

    public const ETAT_CLASSED = 'classed';

    /** @return array<string, string> */
    public static function etatLabels(): array
    {
        return [
            self::ETAT_WAITING => 'À trier',
            self::ETAT_IN_PROGRESS => 'En lavage',
            self::ETAT_TREATED => 'Prêt',
            self::ETAT_CLASSED => 'Classé',
        ];
    }

    /** @return array<string, string> Colonnes kanban atelier (FE). */
    public static function etatColumns(): array
    {
        return [
            self::ETAT_WAITING => 'attente',
            self::ETAT_IN_PROGRESS => 'cours',
            self::ETAT_TREATED => 'traites',
            self::ETAT_CLASSED => 'classes',
        ];
    }

    public static function etatLabel(?string $etat): ?string
    {
        if ($etat === null) {
            return null;
        }

        return self::etatLabels()[$etat] ?? $etat;
    }

    public static function etatColumn(?string $etat): ?string
    {
        if ($etat === null) {
            return null;
        }

        return self::etatColumns()[$etat] ?? null;
    }

    /** @return array{code:string,label:string} « Payé » / « Partiellement payé » / « Non payé » */
    public function paymentStatus(): array
    {
        $code = match (true) {
            (int) $this->left_to_pay === 0 => 'paid',
            (int) $this->advanced > 0 => 'partial',
            default => 'unpaid',
        };

        return ['code' => $code, 'label' => ['paid' => 'Payé', 'partial' => 'Partiellement payé', 'unpaid' => 'Non payé'][$code]];
    }

    /** @return array{code:string,label:string} État atelier, « Retiré » une fois remis au client. */
    public function workshopStatus(): array
    {
        if ($this->retrieved_at) {
            return ['code' => 'retrieved', 'label' => 'Retiré'];
        }

        return ['code' => (string) $this->etat, 'label' => (string) self::etatLabel($this->etat)];
    }

    /**
     * Prestation affichée en liste (Express / Repassage / Classique) à partir des lignes.
     *
     * @return array{code:string,label:string}
     */
    public static function serviceBadge(bool $express, ?int $minAction, ?int $maxAction): array
    {
        return match (true) {
            $express || $maxAction === 1 => ['code' => 'express', 'label' => 'Express'],
            $minAction === 2 => ['code' => 'repassage', 'label' => 'Repassage'],
            default => ['code' => 'classique', 'label' => 'Classique'],
        };
    }

    public function invoiceNumber(): string
    {
        $prefix = Agency::query()->whereKey($this->agency_id)->value('code_prefix') ?: 'SP';

        return sprintf('FAC-%s-%s-%04d', strtoupper($prefix), $this->deposit_date?->format('ymd') ?? now()->format('ymd'), $this->id);
    }

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_ASSIGNED = 'assigned';

    public const DELIVERY_OUT = 'out_for_delivery';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'notification_channel',
        'notification_status',
        'notified_at',
        'notification_read_at',
        'storage_fee',
        'escalated_at',
        'aged_handled_at',
        'retrieve_condition',
        'retrieve_remarks',
        'receiver_type',
        'identity_confirmed',
        'retrieve_override_reason',
        'is_express',
        'express_supplement',
        'terminal',
        'local_ref',
        'storage_location',
        'discount_reason',
        'cancel_reason',
        'client_uuid',
        'code',
        'qr_token',
        'pressing_id',
        'agency_id',
        'currency',
        'client_id',
        'user_id',
        'laveur_id',
        'classeur_id',
        'receiver_id',
        'deposit_date',
        'retrieve_date',
        'retrieved_at',
        'subtotal',
        'discount',
        'total',
        'advanced',
        'left_to_pay',
        'payment_method',
        'status',
        'etat',
        'delivery_status',
        'delivery_round_id',
        'delivery_zone_id',
        'livreur_id',
        'delivered_at',
        'delivery_confirmation_type',
        'delivery_confirmation_value',
        'delivery_confirmed_at',
        'delivery_address',
        'receiver_name',
        'notes',
        'collection_fee',
        'delivery_fee',
        'points_earned',
        'points_redeemed',
        'promo_code',
        'discount_percent',
    ];

    protected $casts = [
        'deposit_date' => 'datetime',
        'retrieve_date' => 'datetime',
        'retrieved_at' => 'datetime',
        'delivered_at' => 'datetime',
        'delivery_confirmed_at' => 'datetime',
        'status' => 'boolean',
        'is_express' => 'boolean',
        'notified_at' => 'datetime',
        'notification_read_at' => 'datetime',
        'escalated_at' => 'datetime',
        'aged_handled_at' => 'datetime',
        'identity_confirmed' => 'boolean',
    ];

    /**
     * Colonne « Notification » du centre de retrait.
     *
     * @return array{code:string,label:string,channel:?string,at:?string}
     */
    public function notificationState(): array
    {
        $channel = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'][$this->notification_channel] ?? null;
        [$code, $label] = match (true) {
            (bool) $this->notification_read_at => ['read', "{$channel} lu"],
            $this->notification_status === 'failed' => ['failed', 'Échec d’envoi'],
            $this->notified_at && $this->notified_at->lt(now()->subDay()) && ! $this->retrieved_at => ['reminder_due', 'Relance requise'],
            (bool) $this->notified_at => ['sent', "{$channel} envoyé"],
            default => ['to_notify', 'À notifier'],
        };

        return [
            'code' => $code,
            'label' => $label,
            'channel' => $this->notification_channel,
            'at' => ($this->notification_read_at ?? $this->notified_at)?->toIso8601String(),
        ];
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function laveur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'laveur_id');
    }

    public function classeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classeur_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(DepositUnit::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function deliveryRound(): BelongsTo
    {
        return $this->belongsTo(DeliveryRound::class, 'delivery_round_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function isOpen(): bool
    {
        return $this->status === true;
    }

    public function isSettled(): bool
    {
        return (int) $this->left_to_pay === 0;
    }

    public function itemsCount(): int
    {
        return (int) $this->units->sum('quantity');
    }
}
