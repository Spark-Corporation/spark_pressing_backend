<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
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

        if (config('spark.force_https') && ! $this->app->environment(['local', 'testing'])) {
            URL::forceScheme('https');
        }
    }
}
