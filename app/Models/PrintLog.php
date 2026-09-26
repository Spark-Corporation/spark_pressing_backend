<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintLog extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'agency_id', 'deposit_id', 'user_id', 'document',
    ];
}
