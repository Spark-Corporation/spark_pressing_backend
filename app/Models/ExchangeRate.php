<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ExchangeRate extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'pressing_id',
        'source_currency',
        'target_currency',
        'rate',
        'rate_date',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'rate_date' => 'date',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public static function rateFor(
        int $pressingId,
        string $source,
        string $target,
        ?CarbonInterface $on = null
    ): ?self {
        $source = strtoupper($source);
        $target = strtoupper($target);
        $on = $on ?? now();

        if ($source === $target) {
            return null;
        }

        return static::query()
            ->where('pressing_id', $pressingId)
            ->where('source_currency', $source)
            ->where('target_currency', $target)
            ->whereDate('rate_date', '<=', $on)
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }

    public static function convert(
        int $amountMinor,
        int $pressingId,
        string $source,
        string $target,
        ?CarbonInterface $on = null
    ): array {
        $source = strtoupper($source);
        $target = strtoupper($target);
        $on = $on ?? now();

        if ($source === $target) {
            return [
                'amount_minor' => $amountMinor,
                'rate' => 1.0,
                'rate_date' => $on->toDateString(),
                'source_currency' => $source,
                'target_currency' => $target,
            ];
        }

        $row = self::rateFor($pressingId, $source, $target, $on);

        if (! $row) {
            throw ValidationException::withMessages([
                'exchange_rate' => "Aucun taux {$source}→{$target} au {$on->toDateString()}.",
            ]);
        }

        return [
            'amount_minor' => (int) round($amountMinor * (float) $row->rate),
            'rate' => (float) $row->rate,
            'rate_date' => $row->rate_date->toDateString(),
            'source_currency' => $source,
            'target_currency' => $target,
            'exchange_rate_id' => $row->id,
        ];
    }
}
