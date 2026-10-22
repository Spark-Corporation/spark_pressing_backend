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
        'first_name',
        'last_name',
        'contact_preference',
        'consent_sms',
        'consent_email',
        'consent_updated_at',
        'loyalty_tier',
        'pickup_slot',
        'favorite_treatment',
        'instructions',
        'archived_at',
        'consent_marketing',
        'phone_verified_at',
        'password_changed_at',
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
        'consent_sms' => 'boolean',
        'consent_email' => 'boolean',
        'consent_marketing' => 'boolean',
        'phone_verified_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'consent_updated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public const CONTACT_PREFERENCES = [
        'whatsapp' => 'WhatsApp',
        'call' => 'Appel',
        'sms' => 'SMS',
        'email' => 'E-mail',
    ];

    /**
     * Palier de fidélité : forcé (`loyalty_tier`) ou déduit des points (config `spark.loyalty_tiers`).
     *
     * @return array{code:string,label:string}
     */
    public function tier(): array
    {
        $tiers = config('spark.loyalty_tiers');
        $code = $this->loyalty_tier && isset($tiers[$this->loyalty_tier])
            ? $this->loyalty_tier
            : self::tierForPoints((int) $this->loyalty_points);

        return ['code' => $code, 'label' => $tiers[$code]['label']];
    }

    public static function tierForPoints(int $points): string
    {
        $code = array_key_first(config('spark.loyalty_tiers'));
        foreach (config('spark.loyalty_tiers') as $key => $tier) {
            if ($points >= $tier['min_points']) {
                $code = $key;
            }
        }

        return $code;
    }

    /** @return array{code:string,label:string} Actif / VIP / Inactif / Archivé */
    public function crmStatus(): array
    {
        $code = match (true) {
            (bool) $this->archived_at => 'archived',
            ! $this->status => 'inactive',
            $this->tier()['code'] === config('spark.vip_tier') => 'vip',
            default => 'active',
        };

        return ['code' => $code, 'label' => ['archived' => 'Archivé', 'inactive' => 'Inactif', 'vip' => 'VIP', 'active' => 'Actif'][$code]];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class)->latest();
    }

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
