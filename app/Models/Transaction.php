<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'client_uuid',
        'deposit_id',
        'pressing_id',
        'agency_id',
        'user_id',
        'amount',
        'type',
        'payment_method',
        'transaction_date',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
