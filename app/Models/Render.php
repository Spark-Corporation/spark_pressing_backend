<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Render extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'title', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }
}
