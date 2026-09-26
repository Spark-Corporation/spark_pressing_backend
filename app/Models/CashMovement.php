<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashMovement extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'client_uuid',
        'pressing_id',
        'agency_id',
        'user_id',
        'category_id',
        'label',
        'type',
        'amount',
        'action_date',
        'validated',
    ];

    protected $casts = [
        'action_date' => 'datetime',
        'validated' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashMovementCategory::class, 'category_id');
    }
}
