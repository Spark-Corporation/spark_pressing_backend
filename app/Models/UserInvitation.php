<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invitation d’un membre (accès valide 72 heures). */
class UserInvitation extends Model
{
    public const VALID_HOURS = 72;

    protected $fillable = ['pressing_id', 'email', 'fullname', 'role', 'agency_ids', 'require_mfa', 'token', 'expires_at', 'accepted_at', 'revoked_at', 'invited_by', 'user_id'];

    protected $casts = [
        'agency_ids' => 'array',
        'require_mfa' => 'boolean',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            $this->expires_at->lt(now()->addDay()) => 'expiring',
            default => 'pending',
        };
    }
}
