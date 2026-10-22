<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Délai de mise à disposition du linge (heures écoulées) pour une agence et une prestation. */
class RetrievalRule extends Model
{
    use BelongsToTenant;

    public const SERVICES = ['classic' => 'Classique', 'express' => 'Express', 'repass' => 'Repassage'];

    protected $fillable = ['pressing_id', 'agency_id', 'service', 'hours', 'status', 'updated_by'];

    protected $casts = ['status' => 'boolean', 'hours' => 'integer'];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** Règle active de l’agence, sinon règle « toutes agences » du pressing. */
    public static function hoursFor(int $pressingId, int $agencyId, string $service): ?int
    {
        $rule = static::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('service', $service)->where('status', true)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->orderByRaw('agency_id is null')->latest('id')->first();

        return $rule?->hours;
    }
}
