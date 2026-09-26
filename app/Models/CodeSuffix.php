<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeSuffix extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'agency_id', 'title', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
