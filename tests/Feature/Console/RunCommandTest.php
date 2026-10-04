<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Obrazmisli\Agentio\Console\Commands\RunCommand;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Process\Process;

it('passes the settings, the session settings and the MCP configs of the package to the loop', function () {
    $project = projectForLoop();
    file_put_contents($project.'/.env', "APP_ENV=local\nexport DB_DATABASE=/srv/main.sqlite\nAGENTIO_TIMEZONE=UTC\nYOUTRACK_URL=x\n");
    $command = app(RunCommand::class);
    $package = dirname(__DIR__, 3);

    $environment = $command->environment(app(Settings::class), MergePolicy::PullRequest, app(LoopState::class));

    expect($environment)->toMatchArray([
        'AGENTIO_ROOT' => $project,
        'YOUTRACK_URL' => 'https://yt.example.com',
        'YOUTRACK_TOKEN' => 'secret-token',
        'AGENTIO_PROJECT' => 'XY',
        'BASE_BRANCH' => 'develop',
        'WORKTREES_DIR' => $project.'/worktrees',
        'MAX_PARALLEL' => '3',
        'MAX_PARALLEL_TASKS' => '4',
        'AGENT_LOOP_INTERVAL' => '60',
        'CLAUDE_BIN' => $project.'/bin/claude',
        'MERGE_POLICY' => 'pull-request',
        'AGENT_LOG_DIR' => $project.'/storage/logs/agents',
        'AGENTIO_STOP_FILE' => $project.'/storage/logs/agents/stop',
        'AGENTIO_SESSION_SETTINGS' => $package.'/resources/claude/settings.json',
        'AGENTIO_MCP_CONFIG' => $package.'/resources/claude/mcp/youtrack.json',
        'AGENTIO_TEST_COMMAND' => 'vendor/bin/pest --compact',
        // The variables Laravel loaded from the project .env stay out of the loop and of the agents' sessions.
        'APP_ENV' => false,
        'DB_DATABASE' => false,
    ])->not->toHaveKey('AGENTIO_TIMEZONE');

    mkdir($project.'/vendor/laravel/boost', 0777, true);

    expect($command->environment(app(Settings::class), MergePolicy::LocalBranch, app(LoopState::class))['AGENTIO_MCP_CONFIG'])
        ->toBe($package.'/resources/claude/mcp/youtrack.json '.$package.'/resources/claude/mcp/laravel-boost.json');
});

it('runs the loop of the package in the project and streams its output', function () {
    projectForLoop(['XY-1']);

    $this->artisan('agentio:run', ['--dry-run' => true])
        ->expectsOutputToContain('PROJECT=XY MERGE_POLICY=local-branch MAX_PARALLEL=3 MAX_PARALLEL_TASKS=4 BASE_BRANCH=develop')
        ->expectsOutputToContain('XY-1 Idea')
        ->assertSuccessful();
});

it('takes the project, the base branch and the merge policy from .agentio.json when the config has none', function () {
    $project = projectForLoop();
    file_put_contents($project.'/.agentio.json', json_encode(['project' => 'AB', 'base_branch' => 'trunk', 'merge_policy' => 'auto-merge']));
    config(['agentio.youtrack.project' => null, 'agentio.base_branch' => null]);

    $this->artisan('agentio:run', ['--dry-run' => true])
        ->expectsOutputToContain('PROJECT=AB MERGE_POLICY=auto-merge MAX_PARALLEL=3 MAX_PARALLEL_TASKS=4 BASE_BRANCH=trunk')
        ->assertSuccessful();

    config(['agentio.merge_policy' => 'whenever']);

    $this->artisan('agentio:run')->expectsOutputToContain('Invalid merge policy')->assertFailed();
});

it('returns the exit code of the loop', function () {
    $project = projectForLoop();
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/loop.pid', (string) getmypid());

    $this->artisan('agentio:run', ['--once' => true])->assertExitCode(1);
});

it('requests a stop without starting the loop', function () {
    $project = projectForLoop();

    $this->artisan('agentio:run', ['--stop' => true])
        ->expectsOutputToContain('Stop requested')
        ->assertSuccessful();

    expect($project.'/storage/logs/agents/stop')->toBeFile()
        ->and($project.'/artisan-calls.log')->not->toBeFile();
});

it('warns about a leftover stop flag and removes it with --fresh', function () {
    $project = projectForLoop();
    mkdir($project.'/storage/logs/agents', 0777, true);
    touch($project.'/storage/logs/agents/stop');

    $this->artisan('agentio:run', ['--once' => true])
        ->expectsOutputToContain('Use --fresh to remove it.')
        ->assertSuccessful();

    expect($project.'/storage/logs/agents/stop')->toBeFile()
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->toContain('stop requested');

    $this->artisan('agentio:run', ['--dry-run' => true])->doesntExpectOutputToContain('Use --fresh')->assertSuccessful();

    $this->artisan('agentio:run', ['--once' => true, '--fresh' => true])
        ->expectsOutputToContain('Removed the stop flag')
        ->assertSuccessful();

    expect($project.'/storage/logs/agents/stop')->not->toBeFile();
});

it('explains what is missing before starting', function () {
    $project = projectForLoop();
    unlink($project.'/.claude/skills/agentio-work-epic/SKILL.md');
    config(['agentio.youtrack.token' => null, 'agentio.claude_binary' => 'claude-that-does-not-exist', 'agentio.worktrees_path' => null]);

    $this->artisan('agentio:run')
        ->expectsOutputToContain('The agentio skills are not installed (.claude/skills/agentio-*): run php artisan agentio:install')
        ->expectsOutputToContain('The worktrees directory is not configured (AGENTIO_WORKTREES_PATH)')
        ->expectsOutputToContain('Claude Code CLI not found (claude-that-does-not-exist)')
        ->expectsOutputToContain('YOUTRACK_TOKEN is not set')
        ->assertFailed();

    expect($project.'/artisan-calls.log')->not->toBeFile();
});

it('prints the dashboard URL when the UI is enabled', function () {
    projectForLoop();
    Route::get('/agentio-test', fn (): string => 'ok')->name('agentio.index');

    $this->artisan('agentio:run', ['--dry-run' => true])->expectsOutputToContain('Dashboard: http://localhost/agentio')->assertSuccessful();

    config(['agentio.ui.enabled' => false]);

    $this->artisan('agentio:run', ['--dry-run' => true])->doesntExpectOutputToContain('Dashboard:')->assertSuccessful();
});

it('forwards SIGTERM to the loop, which finishes its step and exits', function () {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl is not available');
    }

    $project = projectForLoop();
    file_put_contents($project.'/artisan.php', sprintf(<<<'PHP'
        <?php
        require %s;
        $app = Orchestra\Testbench\Foundation\Application::create(options: ['extra' => ['providers' => [Obrazmisli\Agentio\AgentioServiceProvider::class]]]);
        $app->setBasePath(__DIR__);
        config(['agentio.youtrack.url' => 'https://yt.example.com', 'agentio.youtrack.token' => 'secret', 'agentio.claude_binary' => __DIR__.'/bin/claude', 'agentio.logs_path' => __DIR__.'/logs', 'agentio.worktrees_path' => __DIR__.'/worktrees']);
        exit($app->make(Illuminate\Contracts\Console\Kernel::class)->handle(new Symfony\Component\Console\Input\ArgvInput(['artisan', 'agentio:run', '--interval=60']), new Symfony\Component\Console\Output\ConsoleOutput));
        PHP, var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true)));

    $process = new Process([PHP_BINARY, $project.'/artisan.php'], $project, null, null, 60);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'agent loop started'));
    $process->signal(15);
    $process->wait();

    expect($process->getErrorOutput())->toContain('interrupted: finishing the current step and exiting', 'agent loop stopped')
        ->and($process->getExitCode())->toBe(0);
});
