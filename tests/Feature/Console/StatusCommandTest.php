<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();

    $this->logs = hostProject().'/storage/logs/agents';
    mkdir($this->logs, 0777, true);

    config(['agentio.logs_path' => $this->logs, 'agentio.youtrack.url' => null, 'agentio.youtrack.token' => null, 'agentio.youtrack.project' => 'XY']);

    foreach ([LoopState::class, Client::class, IssueRepository::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
});

/**
 * Fake YouTrack answering the project search with the given issues and comments.
 *
 * @param  list<array<string, mixed>>  $issues
 * @param  array<string, list<array<string, mixed>>>  $comments  Issue id => comments
 */
function fakeYouTrackProjectIssues(array $issues, array $comments = []): void
{
    config(['agentio.youtrack.url' => 'https://yt.example.com', 'agentio.youtrack.token' => 'secret']);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(IssueRepository::class);

    Http::fake(function (Request $request) use ($issues, $comments) {
        $path = (string) preg_replace('#^https://yt\.example\.com/api/#', '', strtok($request->url(), '?') ?: '');

        if (preg_match('#^issues/([^/]+)/comments$#', $path, $match) === 1) {
            return Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : ($comments[$match[1]] ?? []));
        }

        return Http::response($path === 'issues' && (int) ($request['$skip'] ?? 0) === 0 ? $issues : []);
    });
}

it('shows the local loop and its live sessions', function () {
    file_put_contents($this->logs.'/loop.pid', (string) getmypid());
    file_put_contents($this->logs.'/loop.log', "[2026-10-03 10:00:00] agent loop started: mode=loop\n[2026-10-03 10:00:05] XY-2: started /agentio-work-epic (pid 1)\n");
    file_put_contents($this->logs.'/XY-2.pid', (string) getmypid());
    file_put_contents($this->logs.'/XY-2.log', "===== 2026-10-03 10:00:05 /agentio-work-epic XY-2 in /tmp/w =====\n"
        .json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'php artisan agentio:yt tree XY-2']]]]])."\n");
    file_put_contents($this->logs.'/XY-9.pid', '999999999');

    $this->artisan('agentio:status', ['--local' => true])
        ->expectsOutputToContain('running (pid '.getmypid().')')
        ->expectsOutputToContain('XY-2: started /agentio-work-epic (pid 1)')
        ->expectsOutputToContain('/agentio-work-epic XY-2 (pid '.getmypid())
        ->expectsOutputToContain('tool_use: php artisan agentio:yt tree XY-2')
        ->doesntExpectOutputToContain('XY-9')
        ->doesntExpectOutputToContain('YouTrack project')
        ->assertSuccessful();
});

it('prints the summary as JSON', function () {
    touch($this->logs.'/stop');
    file_put_contents($this->logs.'/plan-XY-1.pid', (string) getmypid());

    expect(Artisan::call('agentio:status', ['--json' => true, '--local' => true]))->toBe(0);

    $status = json_decode(trim(Artisan::output()), true);

    expect($status['loop'])->toMatchArray(['status' => 'stopped', 'pid' => null, 'stopRequested' => true, 'usageLimit' => null, 'lastLog' => null])
        ->and($status['sessions'])->toHaveCount(1)
        ->and($status['sessions'][0])->toMatchArray(['name' => 'plan-XY-1', 'kind' => 'plan', 'issueId' => 'XY-1', 'lastEvent' => null])
        ->and($status['youtrack'])->toBeNull();
});

it('shows the pause of the loop at the usage limit of Claude Code', function () {
    file_put_contents($this->logs.'/limit', "4102444800 seven_day\n");

    $this->artisan('agentio:status', ['--local' => true])
        ->expectsOutputToContain('paused until 2100-01-01T00:00:00+00:00 (seven_day)')
        ->assertSuccessful();

    expect(Artisan::call('agentio:status', ['--json' => true, '--local' => true]))->toBe(0)
        ->and(json_decode(trim(Artisan::output()), true)['loop']['usageLimit'])->toBe(['resumesAt' => '2100-01-01T00:00:00+00:00', 'window' => 'seven_day']);
});

it('summarises the YouTrack project', function () {
    fakeYouTrackProjectIssues([
        apiIssue('XY-1', ['Type' => 'Idea', 'Stage' => 'Backlog'], tags: ['idea']),
        apiIssue('XY-2', ['Type' => 'Epic', 'Stage' => 'Ready'], ['parent for' => ['XY-3']]),
        apiIssue('XY-3', ['Type' => 'Story', 'Stage' => 'Ready'], ['subtask of' => ['XY-2'], 'parent for' => ['XY-4']]),
        apiIssue('XY-4', ['Type' => 'Task', 'Stage' => 'Ready'], ['subtask of' => ['XY-3']]),
        apiIssue('XY-5', ['Type' => 'Epic', 'Stage' => 'In Progress'], tags: ['agent-claimed']),
        apiIssue('XY-6', ['Type' => 'Task', 'Stage' => 'Blocked'], summary: '[TASK] Waits for a decision'),
        apiIssue('XY-7', ['Type' => 'Epic', 'Stage' => 'Review']),
    ], [
        'XY-6' => [
            ['id' => 'c1', 'text' => "[AGENT:START]\nowner: `host:/w#XY-6`", 'created' => 1791010361184],
            ['id' => 'c2', 'text' => "[AGENT:BLOCKED]\n**Что мешает:** нет макета.", 'created' => 1791010362184],
        ],
    ]);

    $this->artisan('agentio:status')
        ->expectsOutputToContain('YouTrack project XY')
        ->expectsOutputToContain('Ready=1  In Progress=1  Review=1')
        ->expectsOutputToContain('XY-1 Idea [Backlog]')
        ->expectsOutputToContain('first wave: XY-4')
        ->expectsOutputToContain('XY-5 Epic [In Progress]')
        ->expectsOutputToContain('XY-6 Task [Blocked] [TASK] Waits for a decision')
        ->expectsOutputToContain('**Что мешает:** нет макета.')
        ->expectsOutputToContain('XY-7 Epic [Review]')
        ->assertSuccessful();

    expect(Artisan::call('agentio:status', ['--json' => true]))->toBe(0);
    $youTrack = json_decode(trim(Artisan::output()), true)['youtrack'];

    expect($youTrack['counts']['Epic'])->toBe(['Ready' => 1, 'In Progress' => 1, 'Review' => 1])
        ->and(array_column($youTrack['readyEpics'], 'id'))->toBe(['XY-2'])
        ->and($youTrack['blocked'][0])->toMatchArray(['id' => 'XY-6', 'reason' => '**Что мешает:** нет макета.', 'url' => 'https://yt.example.com/issue/XY-6'])
        ->and(array_column($youTrack['awaitingHuman'], 'id'))->toBe(['XY-7']);
});

it('reports YouTrack problems without failing', function () {
    $this->artisan('agentio:status')
        ->expectsOutputToContain('YOUTRACK_URL / YOUTRACK_TOKEN are not set')
        ->assertSuccessful();

    config(['agentio.youtrack.url' => 'https://yt.example.com', 'agentio.youtrack.token' => 'secret']);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(IssueRepository::class);
    Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $this->artisan('agentio:status')->expectsOutputToContain('failed with HTTP 401')->assertSuccessful();
});
