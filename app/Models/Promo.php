<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'code', 'rate_percent', 'starts_at', 'ends_at', 'quota', 'used', 'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function isUsable(): bool
    {
        if (! $this->status) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        if ($this->quota !== null && $this->used >= $this->quota) {
            return false;
        }

        return true;
    }
}
