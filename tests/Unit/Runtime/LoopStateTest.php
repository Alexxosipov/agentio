<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Runtime\LoopLogEntry;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\LoopStatus;
use Obrazmisli\Agentio\Runtime\Session;
use Obrazmisli\Agentio\Runtime\SessionKind;

/** A pid no process can have. */
const DEAD_PID = 2147483646;

/**
 * @return array{0: LoopState, 1: string}
 */
function loopState(): array
{
    $directory = temporaryDirectory();

    return [new LoopState($directory, $directory.'/stop', 'Asia/Shanghai'), $directory];
}

it('lists sessions from pid files and checks whether they are alive', function () {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/TP-2.pid', getmypid()."\n");
    file_put_contents($directory.'/TP-10.pid', (string) DEAD_PID);
    file_put_contents($directory.'/plan-TP-1.pid', (string) getmypid());
    file_put_contents($directory.'/TP-3.pid', 'garbage');
    file_put_contents($directory.'/loop.pid', (string) getmypid());
    file_put_contents($directory.'/TP-2.restarts', "2\n");

    $sessions = $state->sessions();

    expect(array_map(fn (Session $session): string => $session->name, $sessions))->toBe(['plan-TP-1', 'TP-2', 'TP-3', 'TP-10'])
        ->and(array_map(fn (Session $session): string => $session->name, $state->runningSessions()))->toBe(['plan-TP-1', 'TP-2'])
        ->and($sessions[0]->kind)->toBe(SessionKind::Plan)
        ->and($sessions[0]->issueId)->toBe('TP-1')
        ->and($sessions[0]->logPath)->toBe($directory.'/plan-TP-1.log')
        ->and($sessions[0]->restarts)->toBe(0)
        ->and($sessions[1]->toArray())->toMatchArray([
            'name' => 'TP-2',
            'issueId' => 'TP-2',
            'kind' => 'epic',
            'pid' => getmypid(),
            'alive' => true,
            'logPath' => $directory.'/TP-2.log',
            'restarts' => 2,
        ])
        ->and($sessions[1]->startedAt)->not->toBeNull()
        ->and($sessions[2]->pid)->toBeNull()
        ->and($sessions[2]->alive)->toBeFalse()
        ->and($sessions[3]->alive)->toBeFalse();
});

it('finds a single session', function () {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/TP-2.pid', (string) getmypid());

    expect($state->session('TP-2')?->alive)->toBeTrue()
        ->and($state->session('TP-5'))->toBeNull();
});

it('checks whether a process is alive', function () {
    expect(LoopState::isProcessAlive((int) getmypid()))->toBeTrue()
        ->and(LoopState::isProcessAlive(DEAD_PID))->toBeFalse()
        ->and(LoopState::isProcessAlive(0))->toBeFalse()
        ->and(LoopState::isProcessAlive(-5))->toBeFalse();
});

it('requests and clears a stop', function () {
    [$state, $directory] = loopState();

    expect($state->isStopRequested())->toBeFalse()
        ->and($state->stopFile())->toBe($directory.'/stop')
        ->and($state->logsPath())->toBe($directory);

    $state->requestStop();

    expect($state->isStopRequested())->toBeTrue()
        ->and($directory.'/stop')->toBeFile();

    $state->clearStop();
    $state->clearStop();

    expect($state->isStopRequested())->toBeFalse();
});

it('derives the loop status from loop.pid', function () {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/loop.pid', (string) getmypid());

    expect($state->loopPid())->toBe(getmypid())
        ->and($state->status())->toBe(LoopStatus::Running);

    $state->requestStop();

    expect($state->status())->toBe(LoopStatus::Stopping);

    file_put_contents($directory.'/loop.pid', (string) DEAD_PID);

    expect($state->status())->toBe(LoopStatus::Stopped);
});

it('falls back to loop.log markers without loop.pid', function (string $log, LoopStatus $status) {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/loop.log', $log);

    expect($state->loopPid())->toBeNull()
        ->and($state->status())->toBe($status);
})->with([
    'started' => ["[2026-10-03 14:57:38] agent loop started: mode=loop\n[2026-10-03 14:57:39] TP-2: preparing worktree\n", LoopStatus::Running],
    'stopped' => ["[2026-10-03 14:57:38] agent loop started: mode=once\n[2026-10-03 15:37:41] agent loop stopped\n", LoopStatus::Stopped],
    'no markers' => ["[2026-10-03 14:57:39] TP-2: preparing worktree\n", LoopStatus::Stopped],
    'empty' => ['', LoopStatus::Stopped],
]);

it('reports a stopped loop when there is no log at all', function () {
    [$state] = loopState();

    expect($state->status())->toBe(LoopStatus::Stopped)
        ->and($state->loopLog())->toBe([]);
});

it('reads the tail of loop.log', function () {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/loop.log', implode("\n", [
        '[2026-10-03 14:56:35] agent loop started: mode=once max_parallel=2 interval=300s epic=TP-2',
        '[2026-10-03 14:57:38] TP-2: started /agentio-work-epic (pid 547704, worktree /srv/worktrees/TP-2, log /srv/app/storage/logs/agents/TP-2.log)',
        '',
        'Merge made by the \'ort\' strategy.',
        '[2026-10-03 15:37:41] agent loop stopped',
    ])."\n");

    $entries = $state->loopLog(3);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->issueId)->toBe('TP-2')
        ->and($entries[0]->time?->toIso8601String())->toBe('2026-10-03T14:57:38+08:00')
        ->and($entries[1]->time)->toBeNull()
        ->and($entries[1]->message)->toBe('Merge made by the \'ort\' strategy.')
        ->and($entries[2]->toArray())->toBe(['time' => '2026-10-03T15:37:41+08:00', 'message' => 'agent loop stopped', 'issueId' => null])
        ->and($state->loopLog())->toHaveCount(4);
});

it('parses loop.log lines', function () {
    expect(LoopLogEntry::parse('[2026-10-03 14:45:02] TP-1: planning (log x)')->issueId)->toBe('TP-1')
        ->and(LoopLogEntry::parse('[2026-13-45 99:99:99] broken')->time)->not->toBeNull()
        ->and(LoopLogEntry::parse('plain line')->toArray())->toBe(['time' => null, 'message' => 'plain line', 'issueId' => null]);
});

it('locates session logs', function () {
    [$state, $directory] = loopState();
    file_put_contents($directory.'/TP-2.log', "===== 2026-10-03 14:57:38 /work-epic TP-2 in /srv =====\n");

    expect($state->logPath('TP-2'))->toBe($directory.'/TP-2.log')
        ->and($state->sessionLog('TP-2')->tail())->toHaveCount(1)
        ->and($state->sessionLog('TP-2')->tail()[0]->time?->toIso8601String())->toBe('2026-10-03T14:57:38+08:00')
        ->and($state->restarts('TP-2'))->toBe(0);
});

it('reads the pause of the loop at the usage limit', function () {
    [$loop, $directory] = loopState();
    $now = CarbonImmutable::parse('2026-10-05T07:30:00Z');

    expect($loop->usageLimit($now))->toBeNull();

    file_put_contents($directory.'/limit', "1791196860 five_hour\n");

    expect($loop->usageLimit($now)?->resetsAt?->getTimestamp())->toBe(1791196860)
        ->and($loop->usageLimit($now)?->window)->toBe('five_hour')
        ->and($loop->usageLimit(CarbonImmutable::createFromTimestampUTC(1791196860)))->toBeNull();

    file_put_contents($directory.'/limit', "1791196860 -\n");

    expect($loop->usageLimit($now)?->window)->toBeNull();

    file_put_contents($directory.'/limit', "soon\n");

    expect($loop->usageLimit($now))->toBeNull();
});
