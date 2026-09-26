<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DeliveryHour extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'lavage_hour', 'express_hour', 'repassage_hour', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function hoursForAction(int $typeAction): int
    {
        return match ($typeAction) {
            1 => (int) $this->express_hour,
            2 => (int) $this->repassage_hour,
            default => (int) $this->lavage_hour,
        };
    }
}
