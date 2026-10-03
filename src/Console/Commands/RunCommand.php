<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use ValueError;

/**
 * Runs the installed scripts/agent-loop.sh with the settings of config/agentio.php in its environment,
 * streaming its output and forwarding SIGINT / SIGTERM; the exit code is the loop's.
 */
#[AsCommand(name: 'agentio:run')]
final class RunCommand extends Command
{
    public const string SCRIPT = 'scripts/agent-loop.sh';

    /**
     * @var string
     */
    protected $signature = 'agentio:run
        {--once : One pass, then wait for the started epic sessions to finish}
        {--dry-run : Only show what would be started (changes nothing)}
        {--kill : Stop running agent sessions (claims stay; the next run resumes them)}
        {--stop : Ask the running loop to exit after its current step (creates .agent-stop) and return}
        {--fresh : Remove a leftover stop flag before starting}
        {--epic= : Work only on this epic (ideas are not planned)}
        {--no-plan : Do not plan ideas}
        {--no-wait : With --once: start the sessions and exit without waiting}
        {--interval= : Seconds between passes}
        {--max-parallel= : Epics worked on at the same time}
        {--max-parallel-tasks= : Task subagents per epic at the same time}';

    /**
     * @var string
     */
    protected $description = 'Run the autonomous development loop (scripts/agent-loop.sh) with the agentio config';

    private ?Process $process = null;

    public function handle(LoopState $loop): int
    {
        if ($this->option('stop')) {
            $loop->requestStop();
            $this->components->info('Stop requested ('.$loop->stopFile().'): the loop exits after its current step; running sessions finish on their own.');

            return self::SUCCESS;
        }

        $basePath = $this->laravel->basePath();
        $errors = $this->preconditionErrors($basePath);

        if ($errors !== []) {
            $this->components->error('The agent loop cannot start:');

            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $policy = $this->mergePolicy($basePath);

        if ($policy === null) {
            $this->components->error('Invalid AGENTIO_MERGE_POLICY: use local-branch, pull-request or auto-merge.');

            return self::FAILURE;
        }

        $this->handleStopFlag($loop);
        $this->announceDashboard();

        $this->process = new Process([$basePath.'/'.self::SCRIPT, ...$this->scriptArguments()], $basePath, $this->environment($basePath, $policy, $loop), null, null);

        // SIGINT and SIGTERM (numbers, so that the command also loads without the pcntl extension).
        $this->trap([2, 15], function (int $signal): void {
            if ($this->process?->isRunning() === true) {
                $this->process->signal($signal);
            }
        });

        return $this->runProcess($this->process);
    }

    /**
     * The environment of the loop: config values under the names scripts/agent-loop.sh reads. The variables
     * Laravel loaded from the project's .env (APP_ENV, APP_URL, APP_KEY, DB_*, …) are removed (false): they
     * would leak into the agents' sessions and override the .env of every epic worktree and the test
     * environment of phpunit.xml. The agentio and YouTrack ones stay.
     *
     * @return array<string, string|false>
     */
    public function environment(string $basePath, MergePolicy $policy, LoopState $loop): array
    {
        $manifest = Manifest::load($basePath);
        $loaded = array_filter(
            (new EnvFile($basePath.'/.env'))->keys(),
            fn (string $key): bool => ! str_starts_with($key, 'AGENTIO_') && ! str_starts_with($key, 'YOUTRACK_'),
        );

        return [...array_fill_keys($loaded, false), ...array_filter([
            'YOUTRACK_URL' => $this->configString('agentio.youtrack.url'),
            'YOUTRACK_TOKEN' => $this->configString('agentio.youtrack.token'),
            'AGENTIO_PROJECT' => $this->configString('agentio.youtrack.project') ?? $manifest->project,
            'BASE_BRANCH' => $this->configString('agentio.base_branch') ?? $manifest->baseBranch,
            'MAX_PARALLEL' => $this->configString('agentio.max_parallel'),
            'MAX_PARALLEL_TASKS' => $this->configString('agentio.max_parallel_tasks'),
            'AGENT_LOOP_INTERVAL' => $this->configString('agentio.interval'),
            'WORKTREES_DIR' => $this->configString('agentio.worktrees_path'),
            'CLAUDE_BIN' => $this->configString('agentio.claude_binary'),
            'MERGE_POLICY' => $policy->value,
            'AGENT_LOG_DIR' => $loop->logsPath(),
            'AGENTIO_TEST_COMMAND' => $this->configString('agentio.tests.command'),
            'AGENTIO_FULL_TEST_COMMAND' => $this->configString('agentio.tests.full_command'),
        ], fn (?string $value): bool => $value !== null)];
    }

    /**
     * Options passed through to scripts/agent-loop.sh.
     *
     * @return list<string>
     */
    public function scriptArguments(): array
    {
        $arguments = [];

        foreach (['once', 'dry-run', 'kill', 'no-plan', 'no-wait'] as $flag) {
            if ($this->option($flag)) {
                $arguments[] = '--'.$flag;
            }
        }

        foreach (['epic', 'interval', 'max-parallel', 'max-parallel-tasks'] as $name) {
            $value = $this->option($name);

            if (is_string($value) && $value !== '') {
                $arguments[] = '--'.$name.'='.$value;
            }
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    private function preconditionErrors(string $basePath): array
    {
        $errors = [];

        foreach ([self::SCRIPT, 'scripts/yt.php', 'scripts/epic-worktree.sh'] as $file) {
            if (! is_file($basePath.'/'.$file)) {
                $errors[] = "{$file} is missing: run php artisan agentio:install";
            }
        }

        if (is_file($basePath.'/'.self::SCRIPT) && ! is_executable($basePath.'/'.self::SCRIPT)) {
            $errors[] = self::SCRIPT.' is not executable: chmod +x '.self::SCRIPT.' (or php artisan agentio:install)';
        }

        $claude = $this->configString('agentio.claude_binary') ?? 'claude';

        if (Preconditions::executable($claude) === null) {
            $errors[] = "Claude Code CLI not found ({$claude}): install it or set AGENTIO_CLAUDE_BIN";
        }

        foreach (['url' => 'YOUTRACK_URL', 'token' => 'YOUTRACK_TOKEN'] as $key => $variable) {
            if ($this->configString('agentio.youtrack.'.$key) === null) {
                $errors[] = "{$variable} is not set: add it to .env (config agentio.youtrack.{$key})";
            }
        }

        return $errors;
    }

    private function handleStopFlag(LoopState $loop): void
    {
        if (! $loop->isStopRequested() || $this->option('dry-run') || $this->option('kill')) {
            return;
        }

        if ($this->option('fresh')) {
            $loop->clearStop();
            $this->components->info('Removed the stop flag '.$loop->stopFile().'.');

            return;
        }

        $this->components->warn('The stop flag '.$loop->stopFile().' is set: the loop will exit right away. Use --fresh to remove it.');
    }

    private function announceDashboard(): void
    {
        if ((bool) config('agentio.ui.enabled', true) && Route::has('agentio.index')) {
            $this->components->info('Dashboard: '.route('agentio.index'));
        }
    }

    private function runProcess(Process $process): int
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface && Process::isTtySupported() && stream_isatty(STDOUT)) {
            $process->setTty(true);
            $process->run();

            return $process->getExitCode() ?? self::FAILURE;
        }

        // Without a TTY the output is relayed line by line, stdout and stderr separately.
        $pending = [Process::OUT => '', Process::ERR => ''];
        $write = function (string $type, string $text) use ($output): void {
            $stream = $type === Process::ERR && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stream->write($text, false, OutputInterface::OUTPUT_RAW);
        };

        $process->run(function (string $type, string $buffer) use (&$pending, $write): void {
            $pending[$type] .= $buffer;

            while (($newline = strpos($pending[$type], "\n")) !== false) {
                $write($type, substr($pending[$type], 0, $newline + 1));
                $pending[$type] = substr($pending[$type], $newline + 1);
            }
        });

        foreach ($pending as $type => $rest) {
            if ($rest !== '') {
                $write($type, $rest);
            }
        }

        return $process->getExitCode() ?? self::FAILURE;
    }

    private function mergePolicy(string $basePath): ?MergePolicy
    {
        try {
            return MergePolicy::resolve($this->configString('agentio.merge_policy'), $basePath.'/CLAUDE.md');
        } catch (ValueError) {
            return null;
        }
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
