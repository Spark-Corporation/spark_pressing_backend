<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LoyalGroup extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'name', 'rate_percent', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_groups')->withTimestamps();
    }
}
