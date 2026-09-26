<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    protected $fillable = [
        'pressing_id', 'plan', 'seats', 'code', 'is_activated',
        'activated_at', 'expires_at', 'status',
    ];

    protected $casts = [
        'is_activated' => 'boolean',
        'status' => 'boolean',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function isValid(): bool
    {
        if (! $this->status || ! $this->is_activated) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}
