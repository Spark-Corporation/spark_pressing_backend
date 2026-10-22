<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Disponibilité et ajustement de prix d’un article par agence. */
class ArticleAgencySetting extends Model
{
    protected $fillable = ['article_id', 'agency_id', 'available', 'price_adjust_percent', 'note'];

    protected $casts = ['available' => 'boolean'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
