<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

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
        'code',
        'category_id',
        'billing_mode',
        'icon',
        'allow_discount',
        'round_to',
        'price_editable',
        'classic_hours',
        'express_hours',
        'repass_hours',
        'priority',
        'accepted_defects',
        'compatible_renders',
        'services',
        'kilo_tiers',
        'price_updated_at',
        'updated_by',
    ];

    protected $casts = [
        'status' => 'boolean',
        'allow_discount' => 'boolean',
        'price_editable' => 'boolean',
        'accepted_defects' => 'array',
        'compatible_renders' => 'array',
        'services' => 'array',
        'kilo_tiers' => 'array',
        'price_updated_at' => 'datetime',
    ];

    public const BILLING_MODES = ['piece' => 'Pièce', 'kilo' => 'Kilo', 'mixed' => 'Mixte'];

    public const SERVICES = ['collection' => 'Collecte', 'delivery' => 'Livraison', 'anti_odor' => 'Traitement anti-odeur'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    public function agencySettings(): HasMany
    {
        return $this->hasMany(ArticleAgencySetting::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

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
