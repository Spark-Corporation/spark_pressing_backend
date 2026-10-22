<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PressingSetting extends Model
{
    protected $fillable = ['pressing_id', 'category', 'values', 'draft', 'updated_by', 'published_at'];

    protected $casts = [
        'values' => 'array',
        'draft' => 'array',
        'published_at' => 'datetime',
    ];
}
