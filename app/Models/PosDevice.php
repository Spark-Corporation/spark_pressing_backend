<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Poste de caisse / tablette autorisé (Sécurité, Mode hors ligne). */
class PosDevice extends Model
{
    use BelongsToTenant;

    public const STATUS_LABELS = ['authorized' => 'Autorisé', 'pending' => 'À approuver', 'revoked' => 'Révoqué', 'expired' => 'Expiré'];

    protected $fillable = [
        'pressing_id', 'agency_id', 'code', 'type', 'label', 'responsible_id', 'status',
        'offline_enabled', 'authorized_at', 'authorized_by', 'expires_at', 'last_seen_at',
    ];

    protected $casts = [
        'offline_enabled' => 'boolean',
        'authorized_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'authorized' && $this->expires_at?->isPast() ? 'expired' : $this->status;
    }
}
