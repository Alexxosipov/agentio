<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Queue\HorizonStatus;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Settings;

/**
 * The bot's processes while agentio:run runs, each separate from the loop: the listener (agentio:telegram listen,
 * output in <logs>/telegram.log) and, when nothing else runs it, Horizon (php artisan horizon, <logs>/horizon.log),
 * the worker of the bot's queue. tick() — called by agentio:run about every second — restarts a process that
 * died (with a growing pause), starts Horizon when the project's own Horizon stopped, and restarts everything
 * when a key of .env they use (AGENTIO_*, YOUTRACK_*: the bot token above all) changed: the children read .env
 * afresh, the variables agentio:run itself loaded from .env are not passed to them.
 */
final class BotSupervisor
{
    /** Seconds between two checks of .env, and of a Horizon started by someone else. */
    private const int ENV_CHECK = 3;

    private const int HORIZON_CHECK = 30;

    /** The longest pause before a crashed process is started again, in seconds. */
    private const int MAX_BACKOFF = 300;

    /** @var array<string, InvokedProcess> */
    private array $processes = [];

    /** @var array<string, array{failures: int, startedAt: int, retryAt: int}> */
    private array $restarts = [];

    private string $fingerprint = '';

    private int $envCheckedAt = 0;

    private int $horizonCheckedAt = 0;

    private bool $running = false;

    /**
     * @param  Closure(string): void  $report  Shown to the person running agentio:run
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly LoopState $loop,
        private readonly Horizon $horizon,
        private readonly Closure $report,
    ) {}

    /**
     * Start the bot's processes when the bot is configured; returns whether it was started.
     */
    public function start(): bool
    {
        $this->fingerprint = BotSettings::fingerprint($this->env());

        if ($this->env()->get(BotSettings::TOKEN) === null) {
            return false;
        }

        $status = $this->horizon->status();

        if ($status === HorizonStatus::Missing) {
            ($this->report)('Telegram bot: Laravel Horizon is not installed, so nothing would work the bot\'s queue; run php artisan agentio:setup-telegram (it installs Horizon). The bot is not started.');

            return false;
        }

        if ($status === HorizonStatus::Unreachable) {
            ($this->report)('Telegram bot: Redis is unreachable for Horizon (php artisan horizon:status); start Redis or fix REDIS_* in .env. The bot is not started.');

            return false;
        }

        $this->running = true;
        $this->startProcess('listener');

        if ($status->isRunning()) {
            ($this->report)('Telegram bot: listening (log '.$this->loop->logPath('telegram').'); the queue is worked by the project\'s running Horizon.');
        } else {
            $this->startProcess('horizon');
            ($this->report)('Telegram bot: listening (log '.$this->loop->logPath('telegram').'); started Horizon for the queue (log '.$this->loop->logPath('horizon').').');
        }

        $this->envCheckedAt = $this->horizonCheckedAt = self::now();

        return true;
    }

    public function tick(): void
    {
        if (self::now() - $this->envCheckedAt >= self::ENV_CHECK) {
            $this->envCheckedAt = self::now();
            $fingerprint = BotSettings::fingerprint($this->env());

            if ($fingerprint !== $this->fingerprint) {
                $this->restart();

                return;
            }
        }

        if (! $this->running) {
            return;
        }

        foreach ($this->processes as $name => $process) {
            if (! $process->running()) {
                unset($this->processes[$name]);
                $this->scheduleRestart($name);
            }
        }

        foreach ($this->restarts as $name => $restart) {
            if (! isset($this->processes[$name]) && $restart['retryAt'] <= self::now()) {
                $this->startProcess($name);
            }
        }

        if (! isset($this->processes['horizon']) && ! isset($this->restarts['horizon']) && self::now() - $this->horizonCheckedAt >= self::HORIZON_CHECK) {
            $this->horizonCheckedAt = self::now();

            if ($this->horizon->status() === HorizonStatus::Inactive) {
                ($this->report)('Telegram bot: the project\'s Horizon stopped, starting it for the queue.');
                $this->startProcess('horizon');
            }
        }
    }

    /**
     * Stop the processes started here (the loop has ended).
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->processes as $process) {
            $process->signal(15);
        }

        for ($waited = 0; $waited < 40 && array_filter($this->processes, fn (InvokedProcess $process): bool => $process->running()) !== []; $waited++) {
            Sleep::for(250)->milliseconds();
        }

        foreach ($this->processes as $process) {
            if ($process->running()) {
                $process->signal(9);
            }
        }

        $this->processes = [];
        $this->restarts = [];
    }

    /**
     * The names of the processes running now: listener, horizon.
     *
     * @return list<string>
     */
    public function running(): array
    {
        return array_keys(array_filter($this->processes, fn (InvokedProcess $process): bool => $process->running()));
    }

    /**
     * The bot's keys of .env changed: every process starts again with them.
     */
    private function restart(): void
    {
        $this->fingerprint = BotSettings::fingerprint($this->env());
        $ownHorizon = isset($this->processes['horizon']);
        ($this->report)('Telegram bot: its settings in .env changed, restarting the bot processes.');
        $this->stop();

        if (! $ownHorizon) {
            // The project's own Horizon: its process manager starts it again, tick() does when nothing does.
            $this->horizon->terminate();
        }

        $this->horizonCheckedAt = self::now();

        if ($this->env()->get(BotSettings::TOKEN) !== null) {
            $this->start();
        } else {
            ($this->report)('Telegram bot: AGENTIO_TELEGRAM_BOT_TOKEN was removed, the bot is stopped.');
        }
    }

    private function startProcess(string $name): void
    {
        $command = $name === 'horizon'
            ? $this->settings->artisan('horizon')
            : $this->settings->artisan('agentio:telegram', 'listen');

        if (! is_dir($this->loop->logsPath())) {
            mkdir($this->loop->logsPath(), 0775, true);
        }

        $this->processes[$name] = Process::path($this->settings->basePath())
            ->env([...$this->freshEnvironment(), 'AGENTIO_CHILD_LOG' => $this->loop->logPath($name === 'horizon' ? 'horizon' : 'telegram')])
            ->forever()
            ->quietly()
            ->start(['bash', '-c', 'exec "$@" >>"$AGENTIO_CHILD_LOG" 2>&1 </dev/null', 'bash', ...$command]);

        $this->restarts[$name] = [...($this->restarts[$name] ?? ['failures' => 0, 'retryAt' => 0]), 'startedAt' => self::now()];
    }

    /**
     * A process ended: start it again after a pause that doubles with every quick failure.
     */
    private function scheduleRestart(string $name): void
    {
        $restart = $this->restarts[$name] ?? ['failures' => 0, 'startedAt' => self::now(), 'retryAt' => 0];
        $failures = self::now() - $restart['startedAt'] >= 60 ? 1 : $restart['failures'] + 1;
        $pause = min(self::MAX_BACKOFF, 5 * 2 ** ($failures - 1));
        $this->restarts[$name] = ['failures' => $failures, 'startedAt' => $restart['startedAt'], 'retryAt' => self::now() + $pause];
        ($this->report)("Telegram bot: the {$name} process ended, starting it again in {$pause}s (log ".$this->loop->logPath($name === 'horizon' ? 'horizon' : 'telegram').').');
    }

    /**
     * The variables Laravel loaded from .env are not passed on: the children read .env themselves, with its
     * current values.
     *
     * @return array<string, false>
     */
    private function freshEnvironment(): array
    {
        return $this->env()->unloaded();
    }

    private static function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    private function env(): EnvFile
    {
        return new EnvFile($this->settings->basePath().'/.env');
    }
}
