<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenant = app(TenantContext::class);

            if (! $tenant->applies()) {
                return;
            }

            $table = $builder->getModel()->getTable();
            $builder->where($table.'.pressing_id', $tenant->pressingId);

            if ($builder->getModel()->tenantScopesToAgency() && $tenant->scopesToAgency()) {
                $builder->where($table.'.agency_id', $tenant->agencyId);
            }
        });

        static::creating(function ($model) {
            $tenant = app(TenantContext::class);

            if ($tenant->pressingId && empty($model->pressing_id)) {
                $model->pressing_id = $tenant->pressingId;
            }

            if ($tenant->agencyId && empty($model->agency_id) && $model->tenantScopesToAgency()) {
                $model->agency_id = $tenant->agencyId;
            }
        });
    }

    public function tenantScopesToAgency(): bool
    {
        return true;
    }

    public function belongsToCurrentTenant(): bool
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->applies()) {
            return $tenant->bypass;
        }

        if ((int) $this->pressing_id !== (int) $tenant->pressingId) {
            return false;
        }

        if ($this->tenantScopesToAgency() && $tenant->scopesToAgency()) {
            return (int) $this->agency_id === (int) $tenant->agencyId;
        }

        return true;
    }
}
