<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyLedger extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'loyalty_points_ledger';

    protected $fillable = [
        'pressing_id', 'client_id', 'deposit_id', 'type', 'points', 'amount_xaf', 'reason',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
