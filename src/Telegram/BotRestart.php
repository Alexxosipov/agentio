<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Settings;

/**
 * Makes every process use the bot settings just written to .env: the cached config is rebuilt (when the project
 * caches it) and Horizon is asked to restart its workers. agentio:run restarts the listener itself when it sees
 * the .env change (BotSupervisor); the loop and the assistant read .env on every start.
 */
final readonly class BotRestart
{
    public function __construct(
        private Application $app,
        private Settings $settings,
        private Horizon $horizon,
    ) {}

    /**
     * @return list<string> What was restarted
     */
    public function afterEnvChange(): array
    {
        $done = [];

        if ($this->app->configurationIsCached()) {
            $cache = Process::path($this->app->basePath())->timeout(120)->run($this->settings->artisan('config:cache'));
            $done[] = $cache->successful() ? 'rebuilt the config cache (php artisan config:cache)' : 'could not rebuild the config cache: run php artisan config:cache';
        }

        if ($this->horizon->terminate()) {
            $done[] = 'asked Horizon to restart its workers (php artisan horizon:terminate)';
        }

        return $done;
    }
}
