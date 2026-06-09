<?php

namespace App\Providers;

use App\Services\Config;
use App\Services\RemoteWpCli;
use App\Services\Rsync;
use App\Services\Ssh;
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
        $this->app->singleton(Config::class, fn () => new Config);
        $this->app->singleton(Rsync::class, fn () => new Rsync);
        $this->app->singleton(Ssh::class, fn () => new Ssh);
        $this->app->singleton(WpCli::class, fn ($app) => new WpCli($app->make(Ssh::class)));
        $this->app->singleton(RemoteWpCli::class, fn ($app) => new RemoteWpCli($app->make(Ssh::class)));
    }
}
