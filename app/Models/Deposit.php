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

    public const ETAT_WAITING = 'waiting';

    public const ETAT_IN_PROGRESS = 'in_progress';

    public const ETAT_TREATED = 'treated';

    public const ETAT_CLASSED = 'classed';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_ASSIGNED = 'assigned';

    public const DELIVERY_OUT = 'out_for_delivery';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
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
    ];

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
