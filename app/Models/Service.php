<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'pressing_id',
        'name',
        'code',
        'legacy_type_action',
        'sort_order',
        'default_hours',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }
}
