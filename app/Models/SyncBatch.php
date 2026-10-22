<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lot de synchronisation envoyé par un poste (retour en ligne). */
class SyncBatch extends Model
{
    protected $fillable = [
        'pressing_id', 'agency_id', 'user_id', 'reference', 'device_code', 'operations', 'succeeded', 'conflicts', 'failed', 'status', 'results',
    ];

    protected $casts = ['results' => 'array'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
