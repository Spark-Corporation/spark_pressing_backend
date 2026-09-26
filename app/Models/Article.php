<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'pressing_id',
        'name',
        'description',
        'image',
        'classic_price',
        'express_price',
        'repass_price',
        'classic_price_kilo',
        'express_price_kilo',
        'repass_price_kilo',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function priceFor(int $typeAction, string $pricingType = 'piece'): int
    {
        $map = [
            'piece' => [0 => 'classic_price', 1 => 'express_price', 2 => 'repass_price'],
            'kilo' => [0 => 'classic_price_kilo', 1 => 'express_price_kilo', 2 => 'repass_price_kilo'],
        ];

        $attribute = $map[$pricingType][$typeAction] ?? 'classic_price';

        return (int) ($this->{$attribute} ?? $this->classic_price);
    }
}
