<?php

namespace App\Providers;

use App\Services\CloudflareService;
use App\Services\OpenproviderService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CloudflareService::class, fn () => new CloudflareService);
        $this->app->singleton(OpenproviderService::class, fn () => new OpenproviderService);
    }
}
