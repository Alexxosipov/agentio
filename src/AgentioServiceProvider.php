<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio;

use Illuminate\Support\ServiceProvider;
use Obrazmisli\Agentio\Console\Commands\AgentioCommand;

class AgentioServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentio.php', 'agentio');

        $this->app->singleton(Agentio::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/agentio.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'agentio');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/agentio.php' => config_path('agentio.php'),
        ], ['agentio', 'agentio-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/agentio'),
        ], ['agentio', 'agentio-views']);

        $this->commands([
            AgentioCommand::class,
        ]);
    }
}
