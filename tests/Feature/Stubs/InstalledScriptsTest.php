<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Install\Preconditions;
use Symfony\Component\Process\Process;

/**
 * A host project with the stubs installed, a fake scripts/yt.php (no YouTrack) and a fake Claude Code
 * binary that records which pid files exist while it runs.
 *
 * @param  list<string>  $ideas
 */
function projectWithInstalledStubs(array $ideas = []): string
{
    $project = hostProject();
    test()->artisan('agentio:install', ['--project' => 'XY'])->assertSuccessful();

    $ideasJson = json_encode(array_map(fn (string $id): array => ['id' => $id, 'summary' => 'Idea'], $ideas));
    file_put_contents($project.'/scripts/yt.php', <<<PHP
        <?php
        \$json = in_array('--json', \$argv, true);
        echo match (\$argv[1] ?? '') {
            'ideas' => \$json ? '{$ideasJson}' : '',
            'sync-stage' => "0 issue(s) checked, 0 fixed\\n",
            default => \$json ? '[]' : '',
        };
        PHP);

    file_put_contents($project.'/claude', "#!/usr/bin/env bash\nsleep 1\nls \"\$AGENT_LOG_DIR\" > \"\$AGENT_LOG_DIR/seen-by-claude.txt\"\necho \"claude \$*\"\n");
    chmod($project.'/claude', 0755);

    return $project;
}

function runInstalledLoop(string $project, string ...$arguments): Process
{
    $process = new Process([$project.'/scripts/agent-loop.sh', ...$arguments], $project, [
        'YOUTRACK_URL' => 'https://yt.example.com',
        'YOUTRACK_TOKEN' => 'secret',
        'CLAUDE_BIN' => $project.'/claude',
        'WORKTREES_DIR' => $project.'/worktrees',
        'AGENT_LOG_DIR' => false,
        'MERGE_POLICY' => false,
    ], null, 60);
    $process->run();

    return $process;
}

beforeEach(function () {
    foreach (['bash', 'setsid', 'git', 'composer'] as $command) {
        if (Preconditions::executable($command) === null) {
            $this->markTestSkipped("{$command} is not available");
        }
    }
});

it('installs scripts that are syntactically valid', function () {
    $project = projectWithInstalledStubs();

    foreach (glob($project.'/scripts/*.sh') ?: [] as $script) {
        $process = new Process(['bash', '-n', $script]);
        expect($process->run())->toBe(0, $script.': '.$process->getErrorOutput());
    }

    foreach ([...glob($project.'/scripts/*.php') ?: [], $project.'/.claude/hooks/guard-bash.php'] as $file) {
        $process = new Process([PHP_BINARY, '-l', $file]);
        expect($process->run())->toBe(0, $file.': '.$process->getOutput());
    }
});

it('keeps loop.pid while running and plan-<ID>.pid while planning', function () {
    $project = projectWithInstalledStubs(['XY-1']);
    $logs = $project.'/storage/logs/agents';

    $process = runInstalledLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($logs.'/seen-by-claude.txt'))->toContain('loop.pid', 'plan-XY-1.pid')
        ->and($logs.'/loop.pid')->not->toBeFile()
        ->and($logs.'/plan-XY-1.pid')->not->toBeFile()
        ->and(file_get_contents($logs.'/loop.log'))->toContain('agent loop started: mode=once policy=local-branch', 'XY-1: planning finished', 'agent loop stopped')
        ->and(file_get_contents($logs.'/plan-XY-1.log'))->toContain('/plan XY-1', 'claude -p /plan XY-1');
});

it('passes the project permission rules to headless sessions, which ignore them in untrusted directories', function () {
    $project = projectWithInstalledStubs(['XY-1']);

    $process = runInstalledLoop($project, '--once');
    $arguments = (string) file_get_contents($project.'/storage/logs/agents/plan-XY-1.log');
    preg_match('/ --settings (\{.*\}) --output-format /', $arguments, $match);
    $settings = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($settings['permissions']['allow'])->toContain('Bash(php scripts/yt.php *)', 'Bash(scripts/agent-commit.sh *)')
        ->and($settings['permissions']['deny'])->toContain('Bash(git push --force*)', 'Edit(scripts/agent-loop.sh)', 'Edit(scripts/yt.php)', 'Edit(.claude/hooks/**)');
});

it('refuses to start a second loop in the same checkout', function () {
    $project = projectWithInstalledStubs();
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/loop.pid', (string) getmypid());

    $process = runInstalledLoop($project, '--once');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Another agent loop is already running (pid '.getmypid().', ')
        ->and(file_get_contents($project.'/storage/logs/agents/loop.pid'))->toBe((string) getmypid());
});

it('removes a stale planning pid file without treating it as an epic', function () {
    $project = projectWithInstalledStubs();
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/plan-XY-3.pid', '999999999');

    $process = runInstalledLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($project.'/storage/logs/agents/plan-XY-3.pid')->not->toBeFile()
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->not->toContain('plan-XY-3: session finished');
});

it('runs the configured test commands', function () {
    $project = projectWithInstalledStubs();
    file_put_contents($project.'/composer.json', json_encode(['scripts' => ['test' => 'echo full-gate-from-composer']]));

    $narrow = new Process([$project.'/scripts/run-tests.sh', '--filter=Profile'], $project, ['AGENTIO_TEST_COMMAND' => 'echo narrow']);
    $full = new Process([$project.'/scripts/run-tests.sh'], $project, ['RUN_TESTS_FULL' => '1', 'AGENTIO_FULL_TEST_COMMAND' => false]);
    $failing = new Process([$project.'/scripts/run-tests.sh'], $project, ['AGENTIO_TEST_COMMAND' => 'exit 7']);

    expect($narrow->run())->toBe(0)
        ->and($narrow->getOutput())->toContain('narrow --filter=Profile', 'exit=0 log='.$project.'/storage/logs/tests/')
        ->and($full->run())->toBe(0)
        ->and($full->getOutput())->toContain('full-gate-from-composer')
        ->and($failing->run())->toBe(7)
        ->and($failing->getOutput())->toContain('exit=7');
});

it('prepares an epic worktree without a frontend toolchain', function () {
    $project = projectWithInstalledStubs();
    file_put_contents($project.'/composer.json', json_encode(['name' => 'acme/app', 'require' => new stdClass]));
    file_put_contents($project.'/.env.example', "APP_NAME=Acme\nDB_CONNECTION=mysql\n");
    file_put_contents($project.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));
    file_put_contents($project.'/scripts/yt.php', "<?php echo 'profile-page', PHP_EOL;");

    foreach ([['init', '-q', '-b', 'main'], ['add', '-A'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-qm', 'init']] as $git) {
        (new Process(['git', ...$git], $project))->mustRun();
    }

    $process = new Process([$project.'/scripts/epic-worktree.sh', 'XY-12'], $project, ['WORKTREES_DIR' => $project.'/worktrees', 'COMPOSER_HOME' => $project.'/.composer'], null, 120);
    $process->run();
    $worktree = realpath($project.'/worktrees').'/XY-12';

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))->toEndWith($worktree)
        ->and($process->getErrorOutput())->toContain('no bun.lock or package-lock.json: frontend steps skipped', 'environment ready')
        ->and((new Process(['git', 'branch', '--show-current'], $worktree))->mustRun()->getOutput())->toBe("epic/XY-12-profile-page\n")
        ->and(file_get_contents($worktree.'/.env'))->toContain('APP_URL=http://localhost:8112', 'DB_CONNECTION=sqlite', 'DB_DATABASE='.$worktree.'/database/database.sqlite', 'SESSION_COOKIE=xy_12_session')
        ->and($worktree.'/database/database.sqlite')->toBeFile()
        ->and($worktree.'/vendor/autoload.php')->toBeFile();

    $again = new Process([$project.'/scripts/epic-worktree.sh', 'XY-12'], $project, ['WORKTREES_DIR' => $project.'/worktrees']);
    $again->run();

    expect($again->getErrorOutput())->toContain('environment already prepared');
});
