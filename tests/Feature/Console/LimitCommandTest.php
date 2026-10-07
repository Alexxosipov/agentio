<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Runtime\LoopState;

/**
 * A logs directory with the log of session XY-5 whose latest run consists of $lines.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function sessionWithLog(array $lines): void
{
    $project = hostProject();
    config(['agentio.logs_path' => $project.'/logs', 'agentio.limit_retry' => 600]);
    app()->forgetInstance(LoopState::class);
    mkdir($project.'/logs');
    file_put_contents($project.'/logs/XY-5.log', implode("\n", [
        '===== 2026-10-05 15:22:57 /agentio-work-epic XY-5 in /srv =====',
        ...array_map(fn (array $line): string => (string) json_encode($line, JSON_UNESCAPED_UNICODE), $lines),
        '',
    ]));
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-05T07:23:00Z'));

afterEach(fn () => CarbonImmutable::setTestNow());

it('tells the loop to resume a minute after the limit resets', function () {
    sessionWithLog([
        ['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1791196800, 'rateLimitType' => 'five_hour']],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'result' => "You've hit your session limit · resets 6:40pm (Asia/Makassar)"],
    ]);

    $this->artisan('agentio:limit', ['session' => 'XY-5'])->expectsOutput('1791196860 five_hour')->assertSuccessful();
});

it('pauses for AGENTIO_LIMIT_RETRY seconds when the reset is unknown or already past', function (array $line) {
    sessionWithLog([$line]);

    $this->artisan('agentio:limit', ['session' => 'XY-5'])->expectsOutput((CarbonImmutable::now()->getTimestamp() + 600).' -')->assertSuccessful();
})->with([
    'unknown' => [['type' => 'assistant', 'error' => 'rate_limit', 'message' => ['content' => [['type' => 'text', 'text' => 'Rate limited']]]]],
    'past' => [['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1791100000]]],
]);

it('exits 1 without output when the session did not end at the limit', function () {
    sessionWithLog([['type' => 'result', 'subtype' => 'success', 'result' => 'WORK-EPIC XY-5: REVIEW']]);

    $this->artisan('agentio:limit', ['session' => 'XY-5'])->doesntExpectOutputToContain(' ')->assertExitCode(1);
    $this->artisan('agentio:limit', ['session' => 'XY-404'])->assertExitCode(1);
});
