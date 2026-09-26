<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PromoSpecial extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'code', 'rate_percent', 'max_clients', 'used', 'ends_at', 'status',
    ];

    protected $casts = [
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

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        if ($this->max_clients !== null && $this->used >= $this->max_clients) {
            return false;
        }

        return true;
    }
}
