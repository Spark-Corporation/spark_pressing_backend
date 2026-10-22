<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal des SMS / e-mails envoyés (écran Notifications, « Logs récents »). */
class NotificationLog extends Model
{
    public const STATUS_LABELS = ['sent' => 'Envoyé', 'delivered' => 'Livré', 'opened' => 'Ouvert', 'bounced' => 'Rebond', 'failed' => 'Échec', 'scheduled' => 'Différé (horaires silencieux)'];

    protected $fillable = [
        'pressing_id', 'agency_id', 'client_id', 'deposit_id', 'event', 'channel', 'recipient', 'message', 'status', 'error', 'is_test',
    ];

    protected $casts = ['is_test' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
