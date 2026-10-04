<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Obrazmisli\Agentio\Console\Commands\RunCommand;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Process\Process;

beforeEach(function () {
    foreach (['bash', 'setsid', 'git', 'composer'] as $command) {
        if (Preconditions::executable($command) === null) {
            $this->markTestSkipped("{$command} is not available");
        }
    }
});

/**
 * Run the loop of the package directly with the environment agentio:run gives it.
 */
function runPackageLoop(string $project, string ...$arguments): Process
{
    $environment = app(RunCommand::class)->environment(app(Settings::class), MergePolicy::LocalBranch, app(LoopState::class));
    $process = new Process([RunCommand::packageScript(RunCommand::SCRIPT), ...$arguments], $project, $environment, null, 60);
    $process->run();

    return $process;
}

function initRepository(string $project): void
{
    foreach ([['init', '-q', '-b', 'main'], ['add', '-A'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-qm', 'init']] as $git) {
        (new Process(['git', ...$git], $project))->mustRun();
    }
}

it('ships scripts that are syntactically valid and run only through artisan', function () {
    foreach (glob(dirname(__DIR__, 3).'/scripts/*.sh') ?: [] as $script) {
        $syntax = new Process(['bash', '-n', $script]);
        $direct = new Process([$script, 'XY-1', 'message', 'file'], sys_get_temp_dir(), ['AGENTIO_ROOT' => false, 'WORKTREES_DIR' => false]);

        expect($syntax->run())->toBe(0, $script.': '.$syntax->getErrorOutput())
            ->and($direct->run())->toBe(64, $script)
            ->and($direct->getErrorOutput())->toContain('php artisan agentio:');
    }
});

it('keeps loop.pid while running and plan-<ID>.pid while planning', function () {
    $project = projectForLoop(['XY-1']);
    $logs = $project.'/storage/logs/agents';

    $process = runPackageLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($logs.'/seen-by-claude.txt'))->toContain('loop.pid', 'plan-XY-1.pid')
        ->and($logs.'/loop.pid')->not->toBeFile()
        ->and($logs.'/plan-XY-1.pid')->not->toBeFile()
        ->and(file_get_contents($logs.'/loop.log'))->toContain('agent loop started: mode=once policy=local-branch', 'XY-1: planning finished', 'agent loop stopped')
        ->and(file_get_contents($logs.'/plan-XY-1.log'))->toContain('/agentio-plan XY-1', 'claude -p /agentio-plan XY-1')
        ->and(file_get_contents($project.'/artisan-calls.log'))->toContain('agentio:yt claimed-epics --json', 'agentio:yt ready-epics --json', 'agentio:yt ideas --json');
});

it('gives headless sessions the session settings of the package, the project rules and the MCP configs', function () {
    $project = projectForLoop(['XY-1']);
    file_put_contents($project.'/.claude/settings.json', json_encode(['permissions' => ['allow' => ['Bash(make lint)'], 'deny' => ['Read(./secrets/**)']]]));

    $process = runPackageLoop($project, '--once');
    $arguments = (string) file_get_contents($project.'/storage/logs/agents/plan-XY-1.log');
    preg_match('/ --settings (\{.*\}) --output-format /', $arguments, $match);
    $settings = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    $package = dirname(__DIR__, 3);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($arguments)->toContain('--permission-mode dontAsk --strict-mcp-config --mcp-config '.$package.'/resources/claude/mcp/youtrack.json --settings')
        ->and($settings['permissions']['allow'])->toContain('Bash(php artisan agentio:yt *)', 'Bash(php artisan agentio:commit *)', 'Bash(php artisan agentio:test *)', 'mcp__youtrack__*', 'Skill', 'Edit(./**)', 'Bash(make lint)', 'Bash(git push -u origin XY-*)')
        // "/**" is relative to the main checkout in a git worktree: it would not let the agents edit the epic worktree.
        ->and($settings['permissions']['allow'])->not->toContain('Edit(/**)')
        ->and($settings['permissions']['deny'])->toContain('Bash(git push --force*)', 'Bash(git push origin develop*)', 'Bash(git checkout develop*)', 'Bash(git push origin main*)', 'Bash(php artisan agentio:accept*)', 'Read(./.env)', 'Read(**/.env)', 'Edit(.claude/skills/agentio-*/**)', 'Read(./secrets/**)')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe('php "$CLAUDE_PROJECT_DIR"/artisan agentio:guard');
});

it('refuses to start a second loop in the same checkout', function () {
    $project = projectForLoop();
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/loop.pid', (string) getmypid());

    $process = runPackageLoop($project, '--once');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Another agent loop is already running (pid '.getmypid().', ')
        ->and(file_get_contents($project.'/storage/logs/agents/loop.pid'))->toBe((string) getmypid());
});

it('removes a stale planning pid file without treating it as an epic', function () {
    $project = projectForLoop();
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/plan-XY-3.pid', '999999999');

    $process = runPackageLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($project.'/storage/logs/agents/plan-XY-3.pid')->not->toBeFile()
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->not->toContain('plan-XY-3: session finished');
});

it('does not start an epic whose skills are not committed to the base branch', function () {
    $project = projectForLoop();
    writeFakeArtisan($project);
    file_put_contents($project.'/artisan', str_replace("'ideas' =>", "'ready-epics' => \$json ? '[{\"id\":\"XY-2\"}]' : '',\n        'ideas' =>", (string) file_get_contents($project.'/artisan')));
    initRepository($project);

    $process = runPackageLoop($project, '--once', '--no-plan');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->toContain('the agentio skills (.claude/skills/agentio-*) are not committed to develop');
});

it('runs the configured test commands through agentio:test', function () {
    $project = projectForLoop();
    file_put_contents($project.'/composer.json', json_encode(['scripts' => ['test' => 'echo full-gate-from-composer']]));
    config(['agentio.tests.command' => 'echo narrow', 'agentio.tests.full_command' => null]);

    expect(Artisan::call('agentio:test', ['arguments' => ['--filter=Profile']]))->toBe(0)
        ->and(Artisan::output())->toContain('narrow --filter=Profile', 'exit=0 log='.$project.'/storage/logs/tests/')
        ->and(Artisan::call('agentio:test', ['--full' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('full-gate-from-composer');

    config(['agentio.tests.command' => 'exit 7']);

    expect(Artisan::call('agentio:test'))->toBe(7)
        ->and(Artisan::output())->toContain('exit=7');
});

it('commits only the given files of a task through agentio:commit', function () {
    $project = projectForLoop();
    initRepository($project);
    file_put_contents($project.'/mine.txt', "mine\n");
    file_put_contents($project.'/theirs.txt', "theirs\n");

    expect(Artisan::call('agentio:commit', ['task' => 'XY-7', 'commit-message' => 'Add mine', 'files' => ['mine.txt']]))->toBe(0)
        ->and(Artisan::output())->toContain('XY-7: Add mine')
        ->and((new Process(['git', 'status', '--porcelain'], $project))->mustRun()->getOutput())->toBe("?? theirs.txt\n");

    expect(Artisan::call('agentio:commit', ['task' => 'not-an-id', 'commit-message' => 'x', 'files' => ['theirs.txt']]))->toBe(64);
});

it('prepares an epic worktree without a frontend toolchain through agentio:worktree', function () {
    $project = projectForLoop();
    file_put_contents($project.'/composer.json', json_encode(['name' => 'acme/app', 'require' => new stdClass]));
    file_put_contents($project.'/.env.example', "APP_NAME=Acme\nAPP_KEY=\nDB_CONNECTION=mysql\n");
    file_put_contents($project.'/.env', "APP_KEY=base64:main-checkout\nDB_DATABASE=/srv/main.sqlite\n");
    file_put_contents($project.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));
    file_put_contents($project.'/.gitignore', "/.env\n/artisan-calls.log\n/worktrees\n/vendor\n/composer.lock\n*.sqlite\n/storage\n");
    initRepository($project);
    config(['agentio.base_branch' => 'main']);
    putenv('APP_KEY=base64:main-checkout');

    try {
        $status = Artisan::call('agentio:worktree', ['epic' => 'XY-12']);
        $output = Artisan::output();
    } finally {
        putenv('APP_KEY');
    }

    $worktree = realpath($project.'/worktrees').'/XY-12';

    expect($status)->toBe(0, $output)
        ->and($output)->toContain($worktree."\n")
        ->and($output)->toContain('no bun.lock or package-lock.json: frontend steps skipped', 'environment ready')
        ->and((new Process(['git', 'branch', '--show-current'], $worktree))->mustRun()->getOutput())->toBe("XY-12\n")
        ->and(file_get_contents($worktree.'/.env'))->toContain('APP_URL=http://localhost:8112', 'DB_CONNECTION=sqlite', 'DB_DATABASE='.$worktree.'/database/database.sqlite', 'SESSION_COOKIE=xy_12_session', 'APP_KEY=base64:generated')
        ->and($worktree.'/vendor/autoload.php')->toBeFile();

    expect(Artisan::call('agentio:worktree', ['epic' => 'XY-12']))->toBe(0)
        ->and(Artisan::output())->toContain('environment already prepared');

    expect(Artisan::call('agentio:worktree', ['epic' => 'XY-12', '--remove' => true]))->toBe(0)
        ->and(is_dir($worktree))->toBeFalse();
});

it('refuses to prepare a worktree without a configured worktrees directory', function () {
    projectForLoop();
    config(['agentio.worktrees_path' => null]);

    expect(Artisan::call('agentio:worktree', ['epic' => 'XY-12']))->toBe(1)
        ->and(Artisan::output())->toContain('The worktrees directory is not configured');
});
