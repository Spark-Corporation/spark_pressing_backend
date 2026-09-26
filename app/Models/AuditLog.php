<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'pressing_id',
        'agency_id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public static function record(string $action, ?Model $subject = null, array $payload = []): self
    {
        $actor = auth()->user();

        return static::create([
            'pressing_id' => $actor->pressing_id ?? app(\App\Support\TenantContext::class)->pressingId,
            'agency_id' => $actor->agency_id ?? app(\App\Support\TenantContext::class)->agencyId,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'ip_address' => request()?->ip(),
        ]);
    }
}
