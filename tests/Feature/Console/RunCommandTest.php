<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\LoopState;
use Symfony\Component\Process\Process;

/**
 * A host project with a fake scripts/agent-loop.sh that prints its arguments and environment and exits
 * with the given code, and a fake Claude Code binary.
 */
function projectWithFakeLoop(int $exitCode = 0): string
{
    $project = hostProject();
    mkdir($project.'/scripts');
    mkdir($project.'/bin');

    file_put_contents($project.'/scripts/agent-loop.sh', <<<BASH
        #!/usr/bin/env bash
        echo "args: \$*"
        echo "cwd: \$(pwd)"
        echo "env: project=\$AGENTIO_PROJECT base=\$BASE_BRANCH policy=\$MERGE_POLICY parallel=\$MAX_PARALLEL tasks=\$MAX_PARALLEL_TASKS interval=\$AGENT_LOOP_INTERVAL"
        echo "env: worktrees=\$WORKTREES_DIR claude=\$CLAUDE_BIN logs=\$AGENT_LOG_DIR url=\$YOUTRACK_URL token=\${YOUTRACK_TOKEN:+set} tests=\${AGENTIO_TEST_COMMAND:-default}"
        echo "to stderr" >&2
        exit {$exitCode}
        BASH);
    chmod($project.'/scripts/agent-loop.sh', 0755);
    touch($project.'/scripts/yt.php');
    touch($project.'/scripts/epic-worktree.sh');
    file_put_contents($project.'/bin/claude', "#!/usr/bin/env bash\nexit 0\n");
    chmod($project.'/bin/claude', 0755);

    config([
        'agentio.youtrack.url' => 'https://yt.example.com',
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
        'agentio.base_branch' => 'develop',
        'agentio.merge_policy' => null,
        'agentio.max_parallel' => 3,
        'agentio.max_parallel_tasks' => 4,
        'agentio.interval' => 60,
        'agentio.worktrees_path' => $project.'/../worktrees',
        'agentio.claude_binary' => $project.'/bin/claude',
        'agentio.logs_path' => $project.'/storage/logs/agents',
        'agentio.tests.command' => 'vendor/bin/pest --compact',
    ]);
    app()->forgetInstance(LoopState::class);

    return $project;
}

it('runs the loop script with the config in its environment and streams its output', function () {
    $project = projectWithFakeLoop();
    file_put_contents($project.'/CLAUDE.md', "MERGE_POLICY: pull-request\n");

    $this->artisan('agentio:run', ['--once' => true, '--no-plan' => true, '--epic' => 'XY-7', '--max-parallel-tasks' => '1'])
        ->expectsOutputToContain('args: --once --no-plan --epic=XY-7 --max-parallel-tasks=1')
        ->expectsOutputToContain('cwd: '.$project)
        ->expectsOutputToContain('env: project=XY base=develop policy=pull-request parallel=3 tasks=4 interval=60')
        ->expectsOutputToContain("env: worktrees={$project}/../worktrees claude={$project}/bin/claude logs={$project}/storage/logs/agents url=https://yt.example.com token=set tests=vendor/bin/pest --compact")
        ->assertSuccessful();
});

it('returns the exit code of the loop', function () {
    projectWithFakeLoop(exitCode: 3);

    $this->artisan('agentio:run', ['--dry-run' => true])
        ->expectsOutputToContain('args: --dry-run')
        ->assertExitCode(3);
});

it('prefers the configured merge policy over CLAUDE.md', function () {
    $project = projectWithFakeLoop();
    file_put_contents($project.'/CLAUDE.md', "MERGE_POLICY: pull-request\n");
    config(['agentio.merge_policy' => 'auto-merge']);

    $this->artisan('agentio:run')->expectsOutputToContain('policy=auto-merge')->assertSuccessful();

    config(['agentio.merge_policy' => 'whenever']);

    $this->artisan('agentio:run')->expectsOutputToContain('Invalid AGENTIO_MERGE_POLICY')->assertFailed();
});

it('requests a stop without starting the loop', function () {
    $project = projectWithFakeLoop();

    $this->artisan('agentio:run', ['--stop' => true])
        ->expectsOutputToContain('Stop requested')
        ->doesntExpectOutputToContain('args:')
        ->assertSuccessful();

    expect($project.'/.agent-stop')->toBeFile();
});

it('warns about a leftover stop flag and removes it with --fresh', function () {
    $project = projectWithFakeLoop();
    touch($project.'/.agent-stop');

    $this->artisan('agentio:run', ['--once' => true])
        ->expectsOutputToContain('Use --fresh to remove it.')
        ->assertSuccessful();

    expect($project.'/.agent-stop')->toBeFile();

    $this->artisan('agentio:run', ['--dry-run' => true])->doesntExpectOutputToContain('Use --fresh')->assertSuccessful();

    $this->artisan('agentio:run', ['--once' => true, '--fresh' => true])
        ->expectsOutputToContain('Removed the stop flag')
        ->assertSuccessful();

    expect($project.'/.agent-stop')->not->toBeFile();
});

it('explains what is missing before starting', function () {
    $project = projectWithFakeLoop();
    unlink($project.'/scripts/yt.php');
    chmod($project.'/scripts/agent-loop.sh', 0644);
    config(['agentio.youtrack.token' => null, 'agentio.claude_binary' => 'claude-that-does-not-exist']);

    $this->artisan('agentio:run')
        ->expectsOutputToContain('scripts/yt.php is missing: run php artisan agentio:install')
        ->expectsOutputToContain('scripts/agent-loop.sh is not executable')
        ->expectsOutputToContain('Claude Code CLI not found (claude-that-does-not-exist)')
        ->expectsOutputToContain('YOUTRACK_TOKEN is not set')
        ->doesntExpectOutputToContain('args:')
        ->assertFailed();
});

it('prints the dashboard URL when the UI is enabled', function () {
    projectWithFakeLoop();
    Route::get('/agentio-test', fn (): string => 'ok')->name('agentio.index');

    $this->artisan('agentio:run')->expectsOutputToContain('Dashboard: http://localhost/agentio')->assertSuccessful();

    config(['agentio.ui.enabled' => false]);

    $this->artisan('agentio:run')->doesntExpectOutputToContain('Dashboard:')->assertSuccessful();
});

it('forwards SIGTERM to the loop and returns its exit code', function () {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl is not available');
    }

    $project = projectWithFakeLoop();
    file_put_contents($project.'/scripts/agent-loop.sh', "#!/usr/bin/env bash\ntrap 'echo \"loop got TERM\"; exit 5' TERM\necho 'loop started'\nwhile true; do sleep 0.1; done\n");
    file_put_contents($project.'/artisan.php', sprintf(<<<'PHP'
        <?php
        require %s;
        $app = Orchestra\Testbench\Foundation\Application::create(options: ['extra' => ['providers' => [Obrazmisli\Agentio\AgentioServiceProvider::class]]]);
        $app->setBasePath(__DIR__);
        config(['agentio.youtrack.url' => 'https://yt.example.com', 'agentio.youtrack.token' => 'secret', 'agentio.claude_binary' => __DIR__.'/bin/claude', 'agentio.logs_path' => __DIR__.'/logs']);
        exit($app->make(Illuminate\Contracts\Console\Kernel::class)->handle(new Symfony\Component\Console\Input\ArgvInput(['artisan', 'agentio:run']), new Symfony\Component\Console\Output\ConsoleOutput));
        PHP, var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true)));

    $process = new Process([PHP_BINARY, $project.'/artisan.php'], $project, null, null, 30);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'loop started'));
    $process->signal(15);
    $process->wait();

    expect($process->getOutput())->toContain('loop got TERM')
        ->and($process->getExitCode())->toBe(5);
});
