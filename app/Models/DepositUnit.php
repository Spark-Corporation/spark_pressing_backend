<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepositUnit extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'deposit_id',
        'pressing_id',
        'agency_id',
        'article_id',
        'service_id',
        'client_id',
        'designation',
        'pricing_type',
        'quantity',
        'weight_kg',
        'type_action',
        'unit_price',
        'line_total',
        'retrieve_quantity',
        'state',
        'render_id',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
