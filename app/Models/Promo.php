<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'pressing_id', 'code', 'name', 'type', 'rate_percent', 'amount', 'starts_at', 'ends_at', 'quota', 'used', 'status',
        'per_client_limit', 'agency_ids', 'min_amount', 'max_discount', 'stack_with_loyalty', 'published_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'status' => 'boolean',
        'stack_with_loyalty' => 'boolean',
        'agency_ids' => 'array',
    ];

    public const STATUS_LABELS = ['active' => 'Active', 'scheduled' => 'Programmée', 'exhausted' => 'Quota atteint', 'ended' => 'Terminée', 'disabled' => 'Désactivée'];

    protected static function booted(): void
    {
        // Une remise en montant fixe n'a pas de pourcentage (colonne historique non nulle).
        static::saving(fn (Promo $promo) => $promo->rate_percent ??= 0);
    }

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

    /** Statut affiché dans le tableau « Promotions ». */
    public function displayStatus(): string
    {
        return match (true) {
            ! $this->status => 'disabled',
            $this->ends_at && $this->ends_at->isPast() => 'ended',
            $this->quota !== null && $this->used >= $this->quota => 'exhausted',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }

    /** Montant de remise : pourcentage plafonné (`max_discount`) ou montant fixe. */
    public function discountFor(int $goods): int
    {
        $amount = $this->type === 'amount'
            ? (int) $this->amount
            : (int) floor($goods * (int) $this->rate_percent / 100);

        return min($goods, $this->max_discount ? min($amount, (int) $this->max_discount) : $amount);
    }

    /** Motif de refus (agence, minimum, limite par client, cumul fidélité) ou null. */
    public function refusalFor(Client $client, ?int $agencyId, int $goods, bool $withPoints): ?string
    {
        return match (true) {
            $this->agency_ids && $agencyId && ! in_array($agencyId, array_map('intval', $this->agency_ids), true) => 'Code promo non valable dans cette agence.',
            $goods < (int) $this->min_amount => 'Montant minimum non atteint pour ce code promo ('.number_format((int) $this->min_amount, 0, ',', ' ').' FCFA).',
            $this->per_client_limit !== null && Deposit::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('promo_code', $this->code)->count() >= $this->per_client_limit => 'Limite d’utilisation atteinte pour ce client.',
            $withPoints && ! $this->stack_with_loyalty => 'Ce code promo n’est pas cumulable avec les points fidélité.',
            default => null,
        };
    }
}
