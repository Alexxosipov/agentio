<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Queue;

use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Settings;
use RuntimeException;

/**
 * Laravel Horizon of the host project, the worker of the bot's queue: checks that it is installed, configured for
 * the queue and running, installs it (with predis/predis when PHP has no redis extension) and restarts it. Every
 * call is a process in the project (composer, php artisan), so it sees the project's own vendor and .env.
 */
final readonly class Horizon
{
    public function __construct(private Settings $settings) {}

    public function isInstalled(): bool
    {
        return is_dir($this->settings->basePath().'/vendor/laravel/horizon');
    }

    /**
     * Whether Horizon's config (config/horizon.php) has been published.
     */
    public function isConfigured(): bool
    {
        return is_file($this->settings->basePath().'/config/horizon.php');
    }

    public function status(): HorizonStatus
    {
        if (! $this->isInstalled()) {
            return HorizonStatus::Missing;
        }

        $result = Process::path($this->settings->basePath())->timeout(60)->run($this->settings->artisan('horizon:status'));
        $output = strtolower($result->output()."\n".$result->errorOutput());

        return match (true) {
            $result->successful() && ! str_contains($output, 'inactive') => HorizonStatus::Running,
            str_contains($output, 'paused') => HorizonStatus::Paused,
            str_contains($output, 'not defined') || str_contains($output, 'no commands defined') => HorizonStatus::Missing,
            str_contains($output, 'connection refused') || str_contains($output, 'redisexception') || str_contains($output, 'predis') || str_contains($output, 'class "redis" not found') => HorizonStatus::Unreachable,
            default => HorizonStatus::Inactive,
        };
    }

    /**
     * Install Horizon into the project: composer require laravel/horizon (and predis/predis with REDIS_CLIENT=predis
     * when PHP has no redis extension), then horizon:install publishes its config and service provider.
     *
     * @return list<string> What was done
     *
     * @throws RuntimeException When a step fails
     */
    public function install(): array
    {
        $done = [];

        if (! $this->isInstalled()) {
            $this->run(['composer', 'require', 'laravel/horizon', '--no-interaction'], 'composer require laravel/horizon');
            $done[] = 'composer require laravel/horizon';
        }

        if (! extension_loaded('redis') && ! is_dir($this->settings->basePath().'/vendor/predis/predis')) {
            $this->run(['composer', 'require', 'predis/predis', '--no-interaction'], 'composer require predis/predis');
            $done[] = 'composer require predis/predis (PHP has no redis extension)';
        }

        if (! extension_loaded('redis')) {
            $env = new EnvFile($this->settings->basePath().'/.env');

            if ($env->get('REDIS_CLIENT') !== 'predis' && $env->put(['REDIS_CLIENT' => 'predis'])) {
                $done[] = 'REDIS_CLIENT=predis in .env';
            }
        }

        if (! $this->isConfigured()) {
            $this->run($this->settings->artisan('horizon:install', '--no-interaction'), 'php artisan horizon:install');
            $done[] = 'php artisan horizon:install (config/horizon.php, HorizonServiceProvider)';
        }

        return $done;
    }

    /**
     * Ask the running Horizon to finish its jobs and exit (its process manager, or agentio:run, starts it again
     * with the new settings). Returns whether a running Horizon was asked.
     */
    public function terminate(): bool
    {
        if (! $this->status()->isRunning()) {
            return false;
        }

        return Process::path($this->settings->basePath())->timeout(60)->run($this->settings->artisan('horizon:terminate'))->successful();
    }

    /**
     * The problem with Horizon's supervisors for the queue in the environment, or null when one works it.
     *
     * @param  array<array-key, mixed>|null  $config  config('horizon') of the project
     */
    public static function queueProblem(?array $config, string $environment, string $connection, string $queue): ?string
    {
        if ($config === null) {
            return 'config/horizon.php is missing: run php artisan horizon:install';
        }

        $environments = is_array($config['environments'] ?? null) ? $config['environments'] : [];
        $supervisors = $environments[$environment] ?? $environments['*'] ?? null;

        if (! is_array($supervisors)) {
            return "config/horizon.php has no supervisors for the environment {$environment} (environments.{$environment} or environments.*)";
        }

        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];

        foreach ($supervisors as $name => $supervisor) {
            $supervisor = [...(is_array($defaults[$name] ?? null) ? $defaults[$name] : []), ...(is_array($supervisor) ? $supervisor : [])];
            $queues = is_array($supervisor['queue'] ?? null) ? $supervisor['queue'] : [(string) ($supervisor['queue'] ?? '')];

            if (($supervisor['connection'] ?? 'redis') === $connection && in_array($queue, $queues, true)) {
                return null;
            }
        }

        return "no Horizon supervisor of the environment {$environment} works the queue {$queue} of the connection {$connection}: add it to config/horizon.php";
    }

    /**
     * @param  list<string>  $command
     *
     * @throws RuntimeException
     */
    private function run(array $command, string $name): void
    {
        $result = Process::path($this->settings->basePath())->timeout(900)->run($command);

        if ($result->failed()) {
            throw new RuntimeException($name.' failed: '.trim(mb_substr($result->errorOutput() ?: $result->output(), -1500)));
        }
    }
}
