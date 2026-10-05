<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Queue\HorizonStatus;
use Obrazmisli\Agentio\Settings;

it('finds the supervisor that works the queue', function () {
    $config = [
        'defaults' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default']]],
        'environments' => ['production' => ['supervisor-1' => ['maxProcesses' => 10]], 'local' => ['supervisor-1' => ['queue' => ['emails']]]],
    ];

    expect(Horizon::queueProblem($config, 'production', 'redis', 'default'))->toBeNull()
        ->and(Horizon::queueProblem($config, 'local', 'redis', 'default'))->toContain('no Horizon supervisor of the environment local')
        ->and(Horizon::queueProblem($config, 'staging', 'redis', 'default'))->toContain('no supervisors for the environment staging')
        ->and(Horizon::queueProblem([...$config, 'environments' => ['*' => ['supervisor-1' => []]]], 'staging', 'redis', 'default'))->toBeNull()
        ->and(Horizon::queueProblem(null, 'local', 'redis', 'default'))->toContain('config/horizon.php is missing');
});

it('reads the status of Horizon', function (int $exit, string $output, HorizonStatus $status) {
    $project = hostProject();
    mkdir($project.'/vendor/laravel/horizon', 0777, true);
    Process::fake(['*horizon:status*' => Process::result($output, exitCode: $exit)]);

    expect((new Horizon(new Settings($project)))->status())->toBe($status);
})->with([
    [0, 'INFO  Horizon is running.', HorizonStatus::Running],
    [1, 'WARN  Horizon is paused.', HorizonStatus::Paused],
    [2, 'ERROR  Horizon is inactive.', HorizonStatus::Inactive],
    [1, 'Connection refused [tcp://127.0.0.1:6379]', HorizonStatus::Unreachable],
]);

it('knows Horizon is missing without running anything', function () {
    Process::fake();

    expect((new Horizon(new Settings(hostProject())))->status())->toBe(HorizonStatus::Missing);
    Process::assertNothingRan();
});

it('installs Horizon, predis without the redis extension, and publishes its config', function () {
    $project = hostProject();
    file_put_contents($project.'/.env', "APP_ENV=local\n");
    Process::fake();

    $done = (new Horizon(new Settings($project)))->install();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'laravel/horizon', '--no-interaction'] && $process->path === $project);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && in_array('horizon:install', $process->command, true));
    expect($done)->toContain('composer require laravel/horizon');

    if (! extension_loaded('redis')) {
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['composer', 'require', 'predis/predis', '--no-interaction']);
        expect((new EnvFile($project.'/.env'))->get('REDIS_CLIENT'))->toBe('predis');
    }
});

it('reports a failed installation step', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Your requirements could not be resolved', exitCode: 2)]);

    (new Horizon(new Settings(hostProject())))->install();
})->throws(RuntimeException::class, 'composer require laravel/horizon failed: Your requirements could not be resolved');
