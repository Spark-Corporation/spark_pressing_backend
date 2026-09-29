<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyPrice extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'pressing_id',
        'agency_id',
        'article_id',
        'service_id',
        'pricing_type',
        'amount_minor',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public static function resolveAmount(
        int $agencyId,
        int $articleId,
        int $serviceId,
        string $pricingType = 'piece',
        ?CarbonInterface $at = null
    ): ?int {
        $at = $at ?? now();

        $row = static::query()
            ->where('agency_id', $agencyId)
            ->where('article_id', $articleId)
            ->where('service_id', $serviceId)
            ->where('pricing_type', $pricingType)
            ->whereDate('valid_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $at);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        return $row ? (int) $row->amount_minor : null;
    }
}
