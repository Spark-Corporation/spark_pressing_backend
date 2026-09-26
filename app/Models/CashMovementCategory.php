<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashMovementCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'pressing_id',
        'name',
        'direction',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
