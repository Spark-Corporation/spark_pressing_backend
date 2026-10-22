<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'fullname',
        'email',
        'phone_number',
        'address',
        'picture',
        'password',
        'pressing_id',
        'agency_id',
        'status',
        'first_name',
        'last_name',
        'job_title',
        'employee_number',
        'preferences',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_enabled_at',
        'mfa_required',
        'password_changed_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'boolean',
        'preferences' => 'array',
        'mfa_secret' => 'encrypted',
        'mfa_recovery_codes' => 'encrypted:array',
        'mfa_enabled_at' => 'datetime',
        'mfa_required' => 'boolean',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class)->withPivot('is_home')->withTimestamps();
    }

    public function displayName(): string
    {
        return $this->fullname ?: $this->name;
    }

    public function canViewAllAgencies(): bool
    {
        return $this->hasAnyRole(['admin', 'manager']);
    }

    public function canAccessAgency(int $agencyId): bool
    {
        $agency = Agency::query()->find($agencyId);

        if (! $agency || (int) $agency->pressing_id !== (int) $this->pressing_id) {
            return false;
        }

        if ($this->canViewAllAgencies()) {
            return true;
        }

        if ((int) $this->agency_id === $agencyId) {
            return true;
        }

        return $this->agencies()->where('agencies.id', $agencyId)->exists();
    }

    public function allowedAgencyIds(): array
    {
        if ($this->canViewAllAgencies()) {
            return Agency::query()->where('pressing_id', $this->pressing_id)->pluck('id')->all();
        }

        return collect([$this->agency_id])
            ->merge($this->agencies()->pluck('agencies.id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
