<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\Telegram\BotSupervisor;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * A supervisor of the project with Horizon installed; its reports are collected in $reports.
 *
 * @param  list<string>  $reports
 */
function supervisor(array &$reports): BotSupervisor
{
    $project = app(Settings::class)->basePath();
    @mkdir($project.'/vendor/laravel/horizon', 0777, true);

    return new BotSupervisor(app(Settings::class), app(LoopState::class), app(Horizon::class), function (string $line) use (&$reports): void {
        $reports[] = $line;
    });
}

/**
 * How many times a bot process was started: listener or horizon.
 */
function started(string $name): int
{
    $count = 0;

    try {
        // Looks at every recorded process (the callback never matches) and counts the starts.
        Process::assertRan(function (PendingProcess $process) use ($name, &$count): bool {
            $command = is_array($process->command) ? $process->command : [];

            if ($command !== [] && $command[0] === 'bash' && end($command) === ($name === 'horizon' ? 'horizon' : 'listen')) {
                $count++;
            }

            return false;
        });
    } catch (ExpectationFailedException) {
        // Expected.
    }

    return $count;
}

beforeEach(fn () => Sleep::fake());

it('does nothing without a bot token', function () {
    hostProject();
    Process::fake();
    $reports = [];

    expect(supervisor($reports)->start())->toBeFalse();
    Process::assertNothingRan();
});

it('starts the listener and Horizon in processes of their own that read .env afresh', function () {
    $project = projectWithBot();
    Process::fake([
        '*horizon:status*' => Process::result('Horizon is inactive.', exitCode: 2),
        '*' => Process::describe()->runsFor(iterations: 1000),
    ]);
    $reports = [];
    $bot = supervisor($reports);

    expect($bot->start())->toBeTrue()
        ->and($bot->running())->toBe(['listener', 'horizon'])
        ->and($reports[0])->toContain('started Horizon for the queue');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_slice($process->command, -3) === [$project.'/artisan', 'agentio:telegram', 'listen']
        && $process->environment['AGENTIO_TELEGRAM_BOT_TOKEN'] === false
        && $process->environment['APP_ENV'] === false
        && $process->environment['AGENTIO_CHILD_LOG'] === $project.'/storage/logs/agents/telegram.log');
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && end($process->command) === 'horizon'
        && $process->environment['AGENTIO_CHILD_LOG'] === $project.'/storage/logs/agents/horizon.log');
});

it('leaves a running Horizon of the project alone', function () {
    projectWithBot();
    Process::fake(['*horizon:status*' => Process::result('Horizon is running.'), '*' => Process::describe()->runsFor(iterations: 1000)]);
    $reports = [];
    $bot = supervisor($reports);

    $bot->start();

    expect($bot->running())->toBe(['listener'])->and(started('horizon'))->toBe(0);
});

it('does not start the bot without Horizon or Redis', function (string $output, string $reason) {
    projectWithBot();
    Process::fake(['*horizon:status*' => Process::result($output, exitCode: 1)]);
    $reports = [];

    expect(supervisor($reports)->start())->toBeFalse()->and($reports[0])->toContain($reason);
})->with([
    ['Command "horizon:status" is not defined.', 'Horizon is not installed'],
    ['Connection refused', 'Redis is unreachable'],
]);

it('restarts the bot processes when the token in .env changes', function () {
    $project = projectWithBot();
    Process::fake(['*horizon:status*' => Process::result('Horizon is inactive.', exitCode: 2), '*' => Process::describe()->runsFor(iterations: 1000)]);
    $reports = [];
    $bot = supervisor($reports);
    $bot->start();

    $bot->tick();
    expect(started('listener'))->toBe(1);

    file_put_contents($project.'/.env', str_replace('TEST-token', 'NEW-token', (string) file_get_contents($project.'/.env')));
    $this->travel(5)->seconds();
    $bot->tick();

    expect(started('listener'))->toBe(2)
        ->and(started('horizon'))->toBe(2)
        ->and($reports)->toContain('Telegram bot: its settings in .env changed, restarting the bot processes.');

    file_put_contents($project.'/.env', "APP_ENV=local\n");
    $this->travel(5)->seconds();
    $bot->tick();

    expect($bot->running())->toBe([])->and(end($reports))->toContain('the bot is stopped');
});

it('starts a crashed process again after a growing pause', function () {
    projectWithBot();
    Process::fake(['*horizon:status*' => Process::result('Horizon is running.'), '*' => Process::result()]);
    $reports = [];
    $bot = supervisor($reports);
    $bot->start();

    $bot->tick();
    expect($reports[1])->toContain('the listener process ended, starting it again in 5s');

    $bot->tick();
    expect(started('listener'))->toBe(1);

    $this->travel(6)->seconds();
    $bot->tick();
    $bot->tick();

    expect(started('listener'))->toBe(2)->and($reports[2])->toContain('starting it again in 10s');
});
