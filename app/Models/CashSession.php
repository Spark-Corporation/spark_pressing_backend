<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Session de caisse journalière d’une agence (ouverture → comptage → contrôle → clôture). */
class CashSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'agency_id', 'code', 'business_date', 'opened_at', 'opened_by', 'opening_balance',
        'status', 'counted', 'counted_at', 'counted_by', 'difference', 'difference_reason',
        'controlled_at', 'controlled_by', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opened_at' => 'datetime',
        'counted' => 'array',
        'counted_at' => 'datetime',
        'controlled_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'integer',
        'difference' => 'integer',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controlled_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
