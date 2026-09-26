<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'wallet_ledger';

    protected $fillable = [
        'pressing_id', 'client_id', 'deposit_id', 'type', 'amount', 'reason',
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
