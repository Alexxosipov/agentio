<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Obrazmisli\Agentio\Console\Concerns\RunsPackageScripts;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Runtime\SessionSettings;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use ValueError;

/**
 * Runs the agent loop of the package (scripts/agent-loop.sh, never copied into the project) with the settings of
 * config/agentio.php and .agentio.json in its environment, streaming its output and forwarding SIGINT / SIGTERM;
 * the exit code is the loop's.
 */
#[AsCommand(name: 'agentio:run')]
final class RunCommand extends Command
{
    use RunsPackageScripts;

    public const string SCRIPT = 'agent-loop.sh';

    /**
     * @var string
     */
    protected $signature = 'agentio:run
        {--once : One pass, then wait for the started epic sessions to finish}
        {--dry-run : Only show what would be started (changes nothing)}
        {--kill : Stop running agent sessions (claims stay; the next run resumes them)}
        {--stop : Ask the running loop to exit after its current step (sets the stop flag) and return}
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
    protected $description = 'Run the autonomous development loop of agentio';

    private ?Process $process = null;

    public function handle(LoopState $loop, Settings $settings): int
    {
        if ($this->option('dry-run') && ($this->option('stop') || $this->option('kill'))) {
            $this->components->error('--dry-run changes nothing: it cannot be combined with --stop or --kill.');

            return self::INVALID;
        }

        if ($this->option('stop')) {
            $loop->requestStop();
            $this->components->info('Stop requested ('.$loop->stopFile().'): the loop exits after its current step; running sessions finish on their own.');

            return self::SUCCESS;
        }

        $errors = $this->preconditionErrors($settings);

        if ($errors !== []) {
            $this->components->error('The agent loop cannot start:');

            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        try {
            $policy = $settings->mergePolicy();
        } catch (ValueError) {
            $this->components->error('Invalid merge policy (AGENTIO_MERGE_POLICY or .agentio.json): use local-branch, pull-request or auto-merge.');

            return self::FAILURE;
        }

        $this->handleStopFlag($loop);
        $this->announceDashboard();

        $this->process = new Process(
            [self::packageScript(self::SCRIPT), ...$this->scriptArguments()],
            $settings->basePath(),
            $this->environment($settings, $policy, $loop),
            null,
            null,
        );

        // SIGINT and SIGTERM (numbers, so that the command also loads without the pcntl extension).
        $this->trap([2, 15], function (int $signal): void {
            if ($this->process?->isRunning() === true) {
                $this->process->signal($signal);
            }
        });

        return $this->runScript($this->process);
    }

    /**
     * The environment of the loop: the script environment plus the loop settings, the settings of the headless
     * sessions (SessionSettings, as JSON) and their MCP configs (YouTrack, and Laravel Boost when the project has it).
     *
     * @return array<string, string|false>
     */
    public function environment(Settings $settings, MergePolicy $policy, LoopState $loop): array
    {
        $mcp = [dirname(__DIR__, 3).'/resources/claude/mcp/youtrack.json'];

        if (Installer::hasBoost($settings->basePath())) {
            $mcp[] = dirname(__DIR__, 3).'/resources/claude/mcp/laravel-boost.json';
        }

        return $this->scriptEnvironment($settings, [
            'MAX_PARALLEL' => Settings::string('agentio.max_parallel'),
            'MAX_PARALLEL_TASKS' => Settings::string('agentio.max_parallel_tasks'),
            'AGENT_LOOP_INTERVAL' => Settings::string('agentio.interval'),
            'CLAUDE_BIN' => Settings::string('agentio.claude_binary'),
            'CLAUDE_MODEL' => Settings::string('agentio.claude_model'),
            'MERGE_POLICY' => $policy->value,
            'AGENT_LOG_DIR' => $loop->logsPath(),
            'AGENTIO_STOP_FILE' => $loop->stopFile(),
            'AGENTIO_SESSION_SETTINGS' => (new SessionSettings($settings))->toJson(),
            'AGENTIO_PLANNING_SETTINGS' => (new SessionSettings($settings))->toJson(planning: true),
            'AGENTIO_MCP_CONFIG' => implode(' ', $mcp),
        ]);
    }

    /**
     * Options passed through to the loop script.
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
    private function preconditionErrors(Settings $settings): array
    {
        $errors = [];

        if (! is_file($settings->basePath().'/.claude/skills/agentio-work-epic/SKILL.md')) {
            $errors[] = 'The agentio skills are not installed (.claude/skills/agentio-*): run php artisan agentio:install';
        }

        if ($settings->worktreesPath() === null) {
            $errors[] = 'The worktrees directory is not configured (AGENTIO_WORKTREES_PATH): run php artisan agentio:install';
        }

        $claude = Settings::string('agentio.claude_binary') ?? 'claude';

        if (Preconditions::executable($claude) === null) {
            $errors[] = "Claude Code CLI not found ({$claude}): install it or set AGENTIO_CLAUDE_BIN";
        }

        foreach (['url' => 'YOUTRACK_URL', 'token' => 'YOUTRACK_TOKEN'] as $key => $variable) {
            if (Settings::string('agentio.youtrack.'.$key) === null) {
                $errors[] = "{$variable} is not set: run php artisan agentio:install (it writes .env)";
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
}
