<?php

namespace App\Providers;

use App\Services\CloudflareService;
use App\Services\Config;
use App\Services\OpenproviderService;
use App\Services\Rsync;
use App\Services\WpCli;
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
        $this->app->singleton(Config::class, fn () => new Config);
        $this->app->singleton(WpCli::class, fn () => new WpCli);
        $this->app->singleton(Rsync::class, fn () => new Rsync);
    }
}
