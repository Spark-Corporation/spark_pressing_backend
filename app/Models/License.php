<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class License extends Model
{
    public const STATUSES = ['active', 'expiring', 'expired', 'suspended', 'inactive'];

    protected $fillable = [
        'pressing_id', 'plan', 'periodicity', 'auto_renew', 'seats', 'max_agencies',
        'storage_gb', 'code', 'reference', 'is_activated', 'activated_at',
        'period_started_at', 'expires_at', 'status', 'suspended_at', 'suspension_reason',
    ];

    protected $casts = [
        'is_activated' => 'boolean',
        'status' => 'boolean',
        'auto_renew' => 'boolean',
        'activated_at' => 'datetime',
        'period_started_at' => 'datetime',
        'expires_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (License $license) {
            if (! $license->reference) {
                $country = Agency::query()
                    ->where('pressing_id', $license->pressing_id)
                    ->orderBy('id')
                    ->value('country_code') ?? 'XX';

                $license->forceFill([
                    'reference' => sprintf('LIC-%s-%05d', strtoupper($country), $license->id),
                ])->saveQuietly();
            }
        });
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LicensePayment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(LicensePayment::class)->latestOfMany();
    }

    /** Paiement dont la période couvre aujourd’hui. */
    public function currentPayment(): HasOne
    {
        return $this->hasOne(LicensePayment::class)->ofMany(
            ['period_start' => 'max'],
            fn ($q) => $q->where('status', '!=', 'rejected')
                ->where('period_start', '<=', now())
                ->where('period_end', '>=', now()),
        );
    }

    public function isValid(): bool
    {
        if (! $this->status || ! $this->is_activated) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }

    public function statusCode(): string
    {
        if (! $this->status) {
            return 'suspended';
        }
        if (! $this->is_activated) {
            return 'inactive';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->expires_at && $this->expires_at->lte(now()->addDays((int) config('licensing.expiring_soon_days')))) {
            return 'expiring';
        }

        return 'active';
    }

    /** Filtre `status` du centre des licences ; « active » inclut les licences bientôt expirées. */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        $soon = now()->addDays((int) config('licensing.expiring_soon_days'));
        $running = fn (Builder $q) => $q->where('status', true)->where('is_activated', true);

        return match ($status) {
            'suspended' => $query->where('status', false),
            'inactive' => $query->where('status', true)->where('is_activated', false),
            'expired' => $running($query)->where('expires_at', '<', now()),
            'expiring' => $running($query)->whereBetween('expires_at', [now(), $soon]),
            'active' => $running($query)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            default => $query,
        };
    }

    public function planConfig(): ?array
    {
        return config('licensing.plans.'.$this->plan);
    }

    public function planLabel(): string
    {
        return $this->planConfig()['label'] ?? ucfirst((string) $this->plan);
    }

    public function agenciesLimit(): ?int
    {
        return $this->max_agencies ?? ($this->planConfig()['limits']['agencies'] ?? null);
    }

    public function usersLimit(): ?int
    {
        return $this->seats ?: ($this->planConfig()['limits']['users'] ?? null);
    }

    public function storageLimitGb(): ?int
    {
        return $this->storage_gb ?? ($this->planConfig()['limits']['storage_gb'] ?? null);
    }

    public function priceFor(string $periodicity): ?int
    {
        $price = $this->planConfig()['prices'][$periodicity] ?? null;

        return $price === null ? null : (int) $price;
    }
}
