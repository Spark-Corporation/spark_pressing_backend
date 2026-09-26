<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deposit extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    public const ETAT_WAITING = 'waiting';
    public const ETAT_IN_PROGRESS = 'in_progress';
    public const ETAT_TREATED = 'treated';
    public const ETAT_CLASSED = 'classed';

    protected $fillable = [
        'client_uuid',
        'code',
        'pressing_id',
        'agency_id',
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
