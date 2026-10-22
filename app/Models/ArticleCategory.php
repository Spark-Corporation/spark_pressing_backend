<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['pressing_id', 'name', 'icon', 'shared', 'sort_order'];

    protected $casts = ['shared' => 'boolean'];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }
}
