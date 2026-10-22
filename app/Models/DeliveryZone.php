<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryZone extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'pressing_id',
        'agency_id',
        'name',
        'code',
        'status',
        'quarters',
        'radius_km',
        'departure_address',
        'collection_fee',
        'delivery_fee',
        'verified_at',
    ];

    protected $casts = [
        'status' => 'boolean',
        'quarters' => 'array',
        'radius_km' => 'float',
        'verified_at' => 'datetime',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
