<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryRound extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';

    protected $fillable = [
        'pressing_id',
        'agency_id',
        'livreur_id',
        'delivery_zone_id',
        'round_date',
        'name',
        'status',
    ];

    protected $casts = [
        'round_date' => 'date',
    ];

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'delivery_round_id');
    }
}
