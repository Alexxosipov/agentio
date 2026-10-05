<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Obrazmisli\Agentio\Console\Commands\AcceptCommand;
use Obrazmisli\Agentio\Console\Commands\CommitCommand;
use Obrazmisli\Agentio\Console\Commands\InstallCommand;
use Obrazmisli\Agentio\Console\Commands\LogCommand;
use Obrazmisli\Agentio\Console\Commands\RunCommand;
use Obrazmisli\Agentio\Console\Commands\SetupYouTrackCommand;
use Obrazmisli\Agentio\Console\Commands\StatusCommand;
use Obrazmisli\Agentio\Console\Commands\WorktreeCommand;
use Obrazmisli\Agentio\Console\Commands\YouTrackCommand;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\Http\Middleware\Authorize;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\SystemTimezone;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;

final class AgentioServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentio.php', 'agentio');

        $this->app->bind(Settings::class, fn (Application $app): Settings => new Settings($app->basePath()));

        $this->app->singleton(Client::class, fn (Application $app): Client => new Client(
            url: $this->configString('agentio.youtrack.url'),
            token: $this->configString('agentio.youtrack.token'),
            timeout: (int) config('agentio.youtrack.timeout', 30),
            retries: (int) config('agentio.youtrack.retries', 2),
        ));

        $this->app->singleton(McpClient::class, fn (Application $app): McpClient => new McpClient(
            url: $this->configString('agentio.youtrack.url'),
            token: $this->configString('agentio.youtrack.token'),
            timeout: (int) config('agentio.youtrack.timeout', 30),
            retries: (int) config('agentio.youtrack.retries', 2),
        ));

        $this->app->bind(Tools::class, fn (Application $app): Tools => new Tools($app->make(McpClient::class)));

        $this->app->singleton(IssueRepository::class, fn (Application $app): IssueRepository => new IssueRepository(
            $app->make(Client::class),
            $app->make(Settings::class)->project(),
        ));

        $this->app->singleton(LoopState::class, function (Application $app): LoopState {
            $logs = $this->configString('agentio.logs_path') ?? storage_path('logs/agents');

            return new LoopState($logs, rtrim($logs, '/').'/'.LoopState::STOP_FILE, $this->configString('agentio.timezone') ?? SystemTimezone::detect());
        });

        $this->app->scoped(YouTrackSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineGate();
        $this->registerRoutes();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'agentio');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            SetupYouTrackCommand::class,
            RunCommand::class,
            StatusCommand::class,
            YouTrackCommand::class,
            WorktreeCommand::class,
            AcceptCommand::class,
            CommitCommand::class,
            LogCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/agentio.php' => config_path('agentio.php'),
        ], ['agentio', 'agentio-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/agentio'),
        ], ['agentio', 'agentio-views']);
    }

    /**
     * The "viewAgentio" gate (open the dashboard) and the "manageAgentio" gate (accept or send back an epic from
     * it): everyone in the local environment, otherwise the emails in agentio.ui.allowed_emails. An application
     * may define its own gates or replace both checks with Agentio::auth().
     */
    private function defineGate(): void
    {
        foreach (['viewAgentio', 'manageAgentio'] as $gate) {
            if (! Gate::has($gate)) {
                Gate::define($gate, fn (?Authenticatable $user = null): bool => $this->app->environment('local')
                    || ($user !== null && in_array(data_get($user, 'email'), (array) config('agentio.ui.allowed_emails', []), true)));
            }
        }
    }

    private function registerRoutes(): void
    {
        if (! (bool) config('agentio.ui.enabled', true)) {
            return;
        }

        Route::group([
            'domain' => $this->configString('agentio.ui.domain'),
            'prefix' => $this->configString('agentio.ui.path') ?? 'agentio',
            'middleware' => [...(array) config('agentio.ui.middleware', ['web']), Authorize::class],
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
