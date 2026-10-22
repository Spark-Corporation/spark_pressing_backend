<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PressingSettingVersion extends Model
{
    protected $fillable = ['pressing_id', 'category', 'values', 'published_by'];

    protected $casts = ['values' => 'array'];
}
