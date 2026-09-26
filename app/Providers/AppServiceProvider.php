<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
