<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Obrazmisli\Agentio\Console\Commands\RunCommand;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Runtime\SessionSettings;
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
        ->and(file_get_contents($project.'/artisan-calls.log'))->toContain('agentio:yt resumable --json', 'agentio:yt ready-epics --json', 'agentio:yt ideas --json');
});

it('gives planning sessions read-only settings and the MCP configs', function () {
    $project = projectForLoop(['XY-1']);
    file_put_contents($project.'/.claude/settings.json', json_encode(['permissions' => ['allow' => ['Bash(make lint)'], 'deny' => ['Read(./secrets/**)']]]));

    $process = runPackageLoop($project, '--once');
    $arguments = (string) file_get_contents($project.'/storage/logs/agents/plan-XY-1.log');
    preg_match('/ --settings (\{.*\}) --output-format /', $arguments, $match);
    $settings = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    $package = dirname(__DIR__, 3);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($arguments)->toContain('--permission-mode dontAsk --strict-mcp-config --mcp-config '.$package.'/resources/claude/mcp/youtrack.json --settings')
        ->and($settings['permissions']['allow'])->toContain('Bash(php artisan agentio:yt *)', 'mcp__youtrack__*', 'Skill', 'Read')
        // Planning runs in the developer's main checkout: it changes no file and runs no project command.
        ->and($settings['permissions']['allow'])->not->toContain('Edit(./**)', 'Bash(php artisan *)', 'Bash(git commit *)', 'Bash(make lint)')
        ->and($settings['permissions']['deny'])->toContain('Edit', 'Write', 'Bash(git commit *)', 'Read(./.env)', 'Read(./secrets/**)', 'Bash(git push origin develop*)')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe("php '{$package}/bin/agentio-guard' '--protected=develop,main,master'");
});

it('gives epic sessions the session settings of the package and the project rules', function () {
    $project = projectForLoop();
    file_put_contents($project.'/.claude/settings.json', json_encode(['permissions' => ['allow' => ['Bash(make lint)'], 'deny' => ['Read(./secrets/**)'], 'additionalDirectories' => ['/srv/shared/', '/']]]));
    $package = dirname(__DIR__, 3);

    $settings = (new SessionSettings(app(Settings::class)))->epic();

    expect($settings['permissions']['allow'])->toContain('Bash(php artisan agentio:yt *)', 'Bash(php artisan agentio:commit *)', 'Bash(php artisan agentio:test *)', 'mcp__youtrack__*', 'Skill', 'Edit(./**)', 'Bash(make lint)', 'Bash(git push -u origin XY-*)')
        // "/**" is relative to the main checkout in a git worktree: it would not let the agents edit the epic worktree.
        ->and($settings['permissions']['allow'])->not->toContain('Edit(/**)', 'Bash(php -i)')
        ->and($settings['permissions']['deny'])->toContain('Bash(git push --force*)', 'Bash(git push origin develop*)', 'Bash(git checkout develop*)', 'Bash(git push origin main*)', 'Bash(php artisan agentio:accept*)', 'Read(./.env)', 'Read(**/.env)', 'Edit(.claude/skills/agentio-*/**)', 'Read(./secrets/**)', 'mcp__laravel-boost__tinker', 'mcp__laravel-boost__get-config')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe("php '{$package}/bin/agentio-guard' '--protected=develop,main,master' '--root=/srv/shared'");
});

it('marks an idea Blocked when its planning keeps ending unfinished', function () {
    $project = projectForLoop(['XY-1']);
    file_put_contents($project.'/artisan', str_replace('\'state\' => "Review\\n"', '\'state\' => "Analysis\\n"', (string) file_get_contents($project.'/artisan')));
    mkdir($project.'/storage/logs/agents', 0777, true);
    file_put_contents($project.'/storage/logs/agents/plan-XY-1.restarts', "3 \n");

    $process = runPackageLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->toContain('XY-1: planning ended unfinished 3 times, marked Blocked')
        ->and(file_get_contents($project.'/artisan-calls.log'))->toContain('agentio:yt release XY-1 --state=Blocked --comment=[AGENT:BLOCKED]')
        ->and($project.'/storage/logs/agents/plan-XY-1.restarts')->not->toBeFile();
});

it('counts an unfinished planning and resumes it later', function () {
    $project = projectForLoop(['XY-1']);
    file_put_contents($project.'/artisan', str_replace('\'state\' => "Review\\n"', '\'state\' => "Analysis\\n"', (string) file_get_contents($project.'/artisan')));

    $process = runPackageLoop($project, '--once');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($project.'/storage/logs/agents/loop.log'))->toContain('XY-1: planning ended unfinished in Analysis (attempt 1/3), will be resumed')
        ->and(file_get_contents($project.'/storage/logs/agents/plan-XY-1.restarts'))->toBe("1 \n");
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

it('kills what a test run leaves behind, and only that', function () {
    $project = projectForLoop();
    $other = new Process(['sleep', '30']);
    $other->start();
    config(['agentio.tests.command' => 'sleep 30 & echo $! > '.$project.'/left.pid; echo started']);

    try {
        expect(Artisan::call('agentio:test'))->toBe(0)
            ->and(Artisan::output())->toContain('exit=0');

        $left = (int) file_get_contents($project.'/left.pid');
        usleep(200_000);

        expect(posix_kill($left, 0))->toBeFalse()
            ->and($other->isRunning())->toBeTrue();
    } finally {
        $other->stop(0);
    }
})->skip(! function_exists('posix_kill'), 'needs the posix extension');

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
