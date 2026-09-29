<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pressing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'details',
        'status',
        'pricing_mode',
        'workflow_laveur_enabled',
        'workflow_classeur_enabled',
        'qr_labels_enabled',
        'block_retrieve_if_unpaid',
        'loyalty_points_rate',
        'hours_classic',
        'hours_express',
        'hours_repass',
        'primary_color',
        'secondary_color',
        'logo_path',
        'collection_fee',
        'delivery_fee',
        'reporting_currency',
        'loyalty_redeem_threshold',
        'loyalty_redeem_value',
    ];

    protected $casts = [
        'status' => 'boolean',
        'workflow_laveur_enabled' => 'boolean',
        'workflow_classeur_enabled' => 'boolean',
        'qr_labels_enabled' => 'boolean',
        'block_retrieve_if_unpaid' => 'boolean',
    ];

    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
