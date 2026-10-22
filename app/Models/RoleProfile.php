<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/** Métadonnées d’un rôle personnalisé d’un pressing (libellé, périmètre, niveau de données). */
class RoleProfile extends Model
{
    protected $fillable = ['role_id', 'pressing_id', 'label', 'description', 'scope', 'data_level', 'agency_ids', 'status', 'source', 'created_by'];

    protected $casts = ['agency_ids' => 'array'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
