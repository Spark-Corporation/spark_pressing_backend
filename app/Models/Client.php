<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'pressing_id',
        'agency_id',
        'code',
        'fullname',
        'email',
        'phone_number',
        'fixe_number',
        'address',
        'city',
        'birthday',
        'picture',
        'password',
        'loyalty_points',
        'wallet_balance',
        'sponsor_code',
        'referred_by_id',
        'last_deposit_at',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'birthday' => 'date',
        'status' => 'boolean',
        'last_deposit_at' => 'datetime',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function loyalGroups(): BelongsToMany
    {
        return $this->belongsToMany(LoyalGroup::class, 'client_groups')->withTimestamps();
    }

    public function loyaltyLedger(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
    }

    public function walletLedger(): HasMany
    {
        return $this->hasMany(WalletLedger::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_id');
    }
}
