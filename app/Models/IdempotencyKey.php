<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'key',
        'pressing_id',
        'actor_id',
        'actor_type',
        'route',
        'request_hash',
        'status',
        'response_code',
        'response_body',
        'locked_at',
    ];

    protected $casts = [
        'response_body' => 'array',
        'locked_at' => 'datetime',
    ];
}
