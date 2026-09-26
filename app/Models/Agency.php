<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pressing_id',
        'name',
        'address',
        'contact',
        'country_code',
        'code_prefix',
        'code_suffix',
        'last_deposit_sequence',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function nextCodePrefix(): string
    {
        return $this->code_prefix ?: strtoupper(substr(preg_replace('/\s+/', '', $this->name), 0, 2));
    }
}
