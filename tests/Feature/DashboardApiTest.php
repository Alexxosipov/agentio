<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

/**
 * Point the package at a fake YouTrack and a temporary logs directory.
 */
function dashboardEnvironment(bool $configured = true): string
{
    Sleep::fake();
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => 'local');

    config([
        'agentio.youtrack.url' => $configured ? 'https://yt.example.com' : null,
        'agentio.youtrack.token' => $configured ? 'secret' : null,
        'agentio.youtrack.retries' => 0,
        'cache.default' => 'array',
    ]);

    foreach ([Client::class, IssueRepository::class, YouTrackSource::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $logs = temporaryDirectory();
    app()->instance(LoopState::class, new LoopState($logs, $logs.'/stop', 'UTC'));

    return $logs;
}

/**
 * A small project: idea TP-1 -> epic TP-2 (story TP-3 with tasks), an unplanned idea and a blocked epic.
 *
 * @return list<array<string, mixed>>
 */
function projectIssues(): array
{
    return [
        apiIssue('TP-1', ['Type' => 'Idea', 'Stage' => 'Done'], ['relates to' => ['TP-2']], ['idea'], '[IDEA] Профиль пользователя'),
        apiIssue('TP-2', ['Type' => 'Epic', 'Stage' => 'In Progress'], ['relates to' => ['TP-1'], 'parent for' => ['TP-3']], ['agent-claimed'], '[EPIC] Аватар'),
        apiIssue('TP-3', ['Type' => 'Story', 'Stage' => 'In Progress'], ['subtask of' => ['TP-2'], 'parent for' => ['TP-5', 'TP-6', 'TP-7', 'TP-10']], summary: '[STORY] Загрузка аватара'),
        apiIssue('TP-5', ['Type' => 'Task', 'Stage' => 'Done'], ['subtask of' => ['TP-3']], summary: '[TASK] Миграция'),
        apiIssue('TP-6', ['Type' => 'Task', 'Stage' => 'In Progress'], ['subtask of' => ['TP-3']], ['agent-claimed'], '[TASK] Обработка'),
        apiIssue('TP-7', ['Type' => 'Task', 'Stage' => 'Ready'], ['subtask of' => ['TP-3'], 'depends on' => ['TP-6']], summary: '[TASK] Эндпоинт'),
        apiIssue('TP-10', ['Type' => 'Task', 'Stage' => 'Ready'], ['subtask of' => ['TP-3']], summary: '[TASK] Тест'),
        apiIssue('TP-8', ['Type' => 'Idea', 'Stage' => 'Backlog'], summary: '[IDEA] Уведомления'),
        apiIssue('TP-9', ['Type' => 'Epic', 'Stage' => 'Blocked'], summary: '[EPIC] Платежи'),
    ];
}

/**
 * @return array{id: string, text: string, created: int, author: array{login: string}}
 */
function apiComment(string $id, string $text, int $created = 1791013037440): array
{
    return ['id' => $id, 'text' => $text, 'created' => $created, 'author' => ['login' => 'agent']];
}

/**
 * @param  list<array<string, mixed>>|null  $issues
 */
function fakeYouTrack(?array $issues = null): void
{
    $issues ??= projectIssues();
    $comments = [
        'TP-2' => [apiComment('c1', "[AGENT:START]\nowner: `host:/w/TP-2`\nbranch: `epic/TP-2`\n\nОркестрация эпика")],
        'TP-6' => [apiComment('c2', "[AGENT:START]\nowner: `host:/w/TP-2#TP-6`\n\nДелаю TP-6", 1791013100000)],
        'TP-9' => [apiComment('c3', "[AGENT:BLOCKED]\nНужен ответ: <script>alert(1)</script>\n\nДетали")],
    ];
    $activities = [
        ['id' => 'a2', 'author' => ['login' => 'agent'], 'added' => [[...apiComment('c2', "[AGENT:START]\nowner: `host:/w/TP-2#TP-6`\n\nДелаю TP-6", 1791013100000), 'issue' => ['idReadable' => 'TP-6', 'summary' => '[TASK] Обработка']]]],
        ['id' => 'a1', 'author' => ['login' => 'human'], 'added' => [[...apiComment('c0', 'Просто комментарий'), 'issue' => ['idReadable' => 'TP-2']]]],
        ['id' => 'a0', 'author' => ['login' => 'agent'], 'added' => [[...apiComment('c4', "[AGENT:DONE]\n**Сделано:** миграция"), 'issue' => ['idReadable' => 'TP-5']]]],
    ];

    Http::fake(function (Request $request) use ($issues, $comments, $activities) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/issues' => Http::response($issues),
            $path === '/api/activities' => Http::response($activities),
            preg_match('#^/api/issues/([^/]+)/comments$#', $path, $match) === 1 => Http::response($comments[$match[1]] ?? []),
            default => Http::response(['error_description' => 'Not found'], 404),
        };
    });
}

/**
 * Logs of a running loop: loop.log, loop.pid and a live epic session TP-2, plus a finished planning log.
 */
function runningLoopLogs(string $logs): void
{
    file_put_contents($logs.'/loop.log', implode("\n", [
        '[2026-10-03 14:57:38] agent loop started: mode=loop max_parallel=2 interval=300s',
        '[2026-10-03 14:57:39] TP-2: started /agentio-work-epic (pid 547803)',
    ])."\n");
    file_put_contents($logs.'/loop.pid', (string) getmypid());
    file_put_contents($logs.'/TP-2.pid', (string) getmypid());
    file_put_contents($logs.'/TP-2.restarts', '1');
    copy(__DIR__.'/../Fixtures/session.log', $logs.'/TP-2.log');
    file_put_contents($logs.'/plan-TP-1.log', "===== 2026-10-03 14:40:00 /agentio-plan TP-1 in /srv/app =====\n");
    touch($logs.'/plan-TP-1.log', time() - 3600);
    file_put_contents($logs.'/TP-2.setup.log', "composer install\n");
}

it('reports the header status', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $this->getJson('/agentio/api/status')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'project' => ['key' => 'TP', 'url' => 'https://yt.example.com/issues?q=project%3A%20TP'],
            'youtrack' => ['configured' => true, 'ok' => true, 'error' => null],
            'loop' => ['status' => 'running', 'label' => 'работает', 'pid' => getmypid(), 'stopRequested' => false],
            'sessions' => ['alive' => 1, 'total' => 1],
            'logs' => ['exists' => true],
            'poll' => 5,
        ])
        ->assertJsonStructure(['generatedAt']);
});

it('reports a stop request and a stopped loop', function () {
    $logs = dashboardEnvironment(configured: false);
    file_put_contents($logs.'/loop.pid', (string) getmypid());
    touch($logs.'/stop');

    $this->getJson('/agentio/api/status')->assertOk()->assertJson([
        'loop' => ['status' => 'stopping', 'label' => 'останавливается', 'stopRequested' => true],
    ])->assertJsonMissingPath('mergePolicy');

    unlink($logs.'/loop.pid');

    $this->getJson('/agentio/api/status')->assertJsonPath('loop.label', 'остановлен')->assertJsonPath('loop.usageLimit', null);
});

it('reports the pause of the loop at the usage limit of Claude Code', function () {
    $logs = dashboardEnvironment(configured: false);
    file_put_contents($logs.'/limit', "4102444800 five_hour\n");

    $this->getJson('/agentio/api/status')->assertOk()->assertJsonPath('loop.usageLimit', [
        'resumesAt' => '2100-01-01T00:00:00+00:00',
        'label' => '5-часовой лимит',
    ]);
});

it('shows the live sessions with their issue, claim, stage, current tasks and latest events', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $response = $this->getJson('/agentio/api/sessions')->assertOk();

    $session = $response->json('sessions.0');

    expect($response->json('sessions'))->toHaveCount(1)
        ->and($session)->toMatchArray(['name' => 'TP-2', 'kind' => 'epic', 'pid' => getmypid(), 'alive' => true, 'restarts' => 1, 'logPath' => 'TP-2.log'])
        ->and($session['issue'])->toMatchArray(['id' => 'TP-2', 'summary' => 'Аватар', 'type' => 'Epic', 'url' => 'https://yt.example.com/issue/TP-2', 'owner' => 'host:/w/TP-2'])
        ->and($session['issue']['status'])->toMatchArray(['stage' => 'development', 'tasks' => ['done' => 1, 'total' => 4, 'inProgress' => 1, 'blocked' => 0]])
        ->and($session['issue']['current'])->toHaveCount(1)
        ->and($session['issue']['current'][0])->toMatchArray(['id' => 'TP-6', 'owner' => 'host:/w/TP-2#TP-6', 'since' => '2026-10-03T07:38:20+00:00'])
        ->and(collect($session['events'])->pluck('type')->unique()->values()->all())->not->toContain('result')
        ->and(collect($session['events'])->pluck('type')->all())->toContain('subagent', 'tool_use', 'text')
        ->and(count($session['events']))->toBeLessThanOrEqual(15)
        ->and($session['lastResult']['costUsd'])->toBe(4.450337399999998)
        ->and($session['log']['exists'])->toBeTrue()
        ->and($response->json('recent.0'))->toMatchArray(['name' => 'plan-TP-1', 'kind' => 'plan', 'issueId' => 'TP-1', 'alive' => false])
        ->and($response->json('recent.0.issue.status.stage'))->toBe('development')
        ->and($response->json('recent'))->toHaveCount(1)
        ->and(collect($response->json('claimed'))->pluck('owner', 'id')->all())->toBe(['TP-2' => 'host:/w/TP-2', 'TP-6' => 'host:/w/TP-2#TP-6'])
        ->and($response->json('claimed.1.epicId'))->toBe('TP-2')
        ->and($response->json('youtrack.ok'))->toBeTrue();
});

it('lists a session whose pid file is stale among the recent ones', function () {
    $logs = dashboardEnvironment(configured: false);
    file_put_contents($logs.'/TP-4.pid', '999999999');
    file_put_contents($logs.'/TP-4.log', "===== 2026-10-03 14:00:00 /agentio-work-epic TP-4 in /srv =====\n");

    $this->getJson('/agentio/api/sessions')
        ->assertOk()
        ->assertJsonCount(0, 'sessions')
        ->assertJsonPath('recent.0.name', 'TP-4')
        ->assertJsonPath('recent.0.issue', null)
        ->assertJsonPath('recent.0.events.0.type', 'session_start')
        ->assertJsonPath('claimed', []);
});

it('builds the pipeline of ideas and epics', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $items = collect($this->getJson('/agentio/api/pipeline')->assertOk()->assertJsonCount(8, 'stages')->json('items'))->keyBy('issue.id');

    expect($items->keys()->all())->toContain('TP-1', 'TP-8', 'TP-9')
        ->and($items)->toHaveCount(3)
        ->and($items['TP-1'])->toMatchArray(['kind' => 'idea', 'active' => true])
        ->and($items['TP-1']['status'])->toMatchArray(['stage' => 'development', 'stageLabel' => 'Разработка'])
        ->and($items['TP-1']['epics'][0]['issue'])->toMatchArray(['id' => 'TP-2', 'owner' => 'host:/w/TP-2'])
        ->and($items['TP-1']['epics'][0]['status']['tasks']['total'])->toBe(4)
        ->and($items['TP-8']['status'])->toMatchArray(['stage' => 'idea', 'note' => 'ждёт планирования'])
        ->and($items['TP-9'])->toMatchArray(['kind' => 'epic', 'epics' => []])
        ->and($items['TP-9']['status'])->toMatchArray(['stage' => 'decomposition', 'blocked' => true, 'blockedReason' => 'Нужен ответ: <script>alert(1)</script>']);
});

it('puts finished items after the active ones', function () {
    dashboardEnvironment();
    fakeYouTrack([
        apiIssue('TP-1', ['Type' => 'Epic', 'Stage' => 'Done']),
        apiIssue('TP-2', ['Type' => 'Epic', 'Stage' => 'Analysis']),
    ]);

    expect(collect($this->getJson('/agentio/api/pipeline')->json('items'))->pluck('active', 'issue.id')->all())
        ->toBe(['TP-2' => true, 'TP-1' => false]);
});

it('shows the agent event feed of the project', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $this->getJson('/agentio/api/events')
        ->assertOk()
        ->assertJsonCount(2, 'events')
        ->assertJsonPath('events.0', [
            'issueId' => 'TP-6',
            'issueSummary' => 'Обработка',
            'issueType' => 'Task',
            'url' => 'https://yt.example.com/issue/TP-6',
            'kind' => 'START',
            'body' => "owner: `host:/w/TP-2#TP-6`\n\nДелаю TP-6",
            'author' => 'agent',
            'owner' => 'host:/w/TP-2#TP-6',
            'createdAt' => '2026-10-03T07:38:20+00:00',
        ])
        ->assertJsonPath('events.1.kind', 'DONE');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/activities') && $request['issueQuery'] === 'project: TP');
});

it('tails loop.log', function () {
    runningLoopLogs(dashboardEnvironment(configured: false));

    $this->getJson('/agentio/api/loop-log')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonCount(2, 'entries')
        ->assertJsonPath('entries.1', ['time' => '2026-10-03T14:57:39+00:00', 'message' => 'TP-2: started /agentio-work-epic (pid 547803)', 'issueId' => 'TP-2']);
});

it('reports a missing loop.log', function () {
    dashboardEnvironment(configured: false);

    $this->getJson('/agentio/api/loop-log')->assertOk()->assertExactJson(['exists' => false, 'entries' => []]);
});

it('builds the Kanban board', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $response = $this->getJson('/agentio/api/board')->assertOk();
    $columns = collect($response->json('columns'))->keyBy('state');

    expect($columns->keys()->all())->toBe(['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done'])
        ->and(collect($columns['Ready']['issues'])->keyBy('id')['TP-7'])->toMatchArray(['epicId' => 'TP-2', 'parentId' => 'TP-3', 'unmetDependencies' => ['TP-6']])
        ->and(collect($columns['In Progress']['issues'])->keyBy('id')['TP-6']['owner'])->toBe('host:/w/TP-2#TP-6')
        ->and(collect($columns['Done']['issues'])->pluck('id')->all())->toBe(['TP-1', 'TP-5'])
        ->and($response->json('epics.*.id'))->toBe(['TP-2', 'TP-9'])
        ->and($response->json('types'))->toBe(['Idea', 'Epic', 'Story', 'Task']);
});

it('shows the log of a task: the events of its epic session that mention it', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $response = $this->getJson('/agentio/api/issues/TP-6/log')->assertOk();
    $texts = collect($response->json('events'))->map(fn (array $event): string => $event['subagent'].' '.$event['text'].' '.$event['detail']);

    expect($response->json())->toMatchArray(['id' => 'TP-6', 'url' => 'https://yt.example.com/issue/TP-6', 'filtered' => true, 'lastResult' => null])
        ->and($response->json('issue'))->toMatchArray(['id' => 'TP-6', 'summary' => 'Обработка', 'type' => 'Task', 'state' => 'In Progress', 'epicId' => 'TP-2', 'parentId' => 'TP-3', 'owner' => 'host:/w/TP-2#TP-6'])
        ->and($response->json('session'))->toMatchArray(['name' => 'TP-2', 'kind' => 'epic', 'alive' => true, 'logPath' => 'TP-2.log', 'exists' => true])
        ->and($texts)->not->toBeEmpty()
        ->and($texts->every(fn (string $text): bool => str_contains($text, 'TP-6')))->toBeTrue()
        ->and(collect($response->json('events'))->pluck('type')->all())->toContain('subagent', 'tool_use')
        ->and(collect($response->json('events'))->pluck('text')->all())->not->toContain('GD с WebP доступен. Захватываю задачу.');
});

it('shows the log of a story with the events of its tasks', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $texts = collect($this->getJson('/agentio/api/issues/TP-3/log')->assertOk()->json('events'))->pluck('text');

    expect($texts)->toContain('GD с WebP доступен. Захватываю задачу.', 'Read task context from YouTrack');
});

it('shows the whole session log of an epic and of an idea', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $epic = $this->getJson('/agentio/api/issues/TP-2/log')->assertOk();
    $idea = $this->getJson('/agentio/api/issues/TP-1/log')->assertOk();

    expect($epic->json('filtered'))->toBeFalse()
        ->and($epic->json('session.name'))->toBe('TP-2')
        ->and($epic->json('events.0.type'))->toBe('session_start')
        ->and($epic->json('lastResult.costUsd'))->toBe(4.450337399999998)
        ->and($idea->json('session'))->toMatchArray(['name' => 'plan-TP-1', 'kind' => 'plan', 'alive' => false, 'logPath' => 'plan-TP-1.log'])
        ->and($idea->json('events'))->toHaveCount(1)
        ->and($idea->json('events.0.text'))->toBe('/agentio-plan TP-1 in /srv/app')
        ->and($idea->json('url'))->toBe('https://yt.example.com/issue/TP-1');
});

it('shows no log for an issue no session has worked on', function () {
    runningLoopLogs(dashboardEnvironment());
    fakeYouTrack();

    $this->getJson('/agentio/api/issues/TP-9/log')->assertOk()->assertJson([
        'id' => 'TP-9',
        'url' => 'https://yt.example.com/issue/TP-9',
        'issue' => ['id' => 'TP-9', 'type' => 'Epic'],
        'session' => null,
        'events' => [],
    ]);
    $this->getJson('/agentio/api/issues/not-an-id/log')->assertNotFound();
});

it('shows the own session log of an issue without YouTrack', function () {
    runningLoopLogs(dashboardEnvironment(configured: false));

    $this->getJson('/agentio/api/issues/TP-2/log')->assertOk()
        ->assertJsonPath('url', null)
        ->assertJsonPath('issue', null)
        ->assertJsonPath('session.name', 'TP-2')
        ->assertJsonPath('filtered', false)
        ->assertJsonPath('events.0.type', 'session_start');
    $this->getJson('/agentio/api/issues/TP-6/log')->assertOk()->assertJsonPath('session', null)->assertJsonPath('events', []);

    Http::assertNothingSent();
});

it('shows an epic in detail', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $epic = $this->getJson('/agentio/api/epics/TP-2')->assertOk()->json('epic');

    expect($epic)->toMatchArray(['id' => 'TP-2', 'owner' => 'host:/w/TP-2', 'progress' => ['Done' => 1, 'In Progress' => 1, 'Ready' => 2]])
        ->and($epic['status']['stage'])->toBe('development')
        ->and($epic['tree']['children'][0])->toMatchArray(['id' => 'TP-3', 'type' => 'Story'])
        ->and(collect($epic['tree']['children'][0]['children'])->keyBy('id')['TP-7'])->toMatchArray(['ready' => false, 'dependsOn' => ['TP-6'], 'unmetDependencies' => ['TP-6'], 'children' => []])
        ->and(collect($epic['tree']['children'][0]['children'])->keyBy('id')['TP-10']['ready'])->toBeTrue()
        ->and($epic['stories'][0])->toMatchArray(['id' => 'TP-3', 'done' => 1, 'total' => 4])
        ->and(collect($epic['readyTasks'])->pluck('id')->all())->toBe(['TP-10'])
        ->and($epic['waiting'])->toBe([['id' => 'TP-7', 'state' => 'Ready', 'waitingFor' => ['TP-6']]])
        ->and(collect($epic['events'])->pluck('issueId')->all())->toBe(['TP-6', 'TP-5'])
        ->and($epic['events'][0]['issueSummary'])->toBe('Обработка');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/activities')
        && $request['issueQuery'] === 'issue ID: TP-2, TP-3, TP-5, TP-6, TP-7, TP-10');
});

it('answers 404 for an issue YouTrack does not know', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $this->getJson('/agentio/api/epics/TP-404')->assertNotFound()->assertJson(['message' => 'Задача TP-404 не найдена.']);
    $this->getJson('/agentio/api/epics/not-an-id')->assertNotFound();
});

it('keeps working without YouTrack configured', function () {
    runningLoopLogs(dashboardEnvironment(configured: false));

    $notConfigured = ['configured' => false, 'ok' => false, 'error' => null];

    $this->getJson('/agentio/api/status')->assertOk()->assertJson(['project' => ['url' => null], 'youtrack' => $notConfigured, 'sessions' => ['alive' => 1]]);
    $this->getJson('/agentio/api/sessions')->assertOk()->assertJsonPath('sessions.0.issue', null)->assertJsonPath('sessions.0.name', 'TP-2')->assertJsonPath('youtrack', $notConfigured);
    $this->getJson('/agentio/api/pipeline')->assertOk()->assertJsonPath('items', [])->assertJsonPath('youtrack', $notConfigured);
    $this->getJson('/agentio/api/events')->assertOk()->assertJsonPath('events', []);
    $this->getJson('/agentio/api/board')->assertOk()->assertJsonCount(7, 'columns')->assertJsonPath('epics', []);
    $this->getJson('/agentio/api/epics/TP-2')->assertOk()->assertJsonPath('epic', null)->assertJsonPath('youtrack', $notConfigured);

    Http::assertNothingSent();
});

it('reports a failing YouTrack without failing the dashboard', function () {
    runningLoopLogs(dashboardEnvironment());
    Http::fake(['yt.example.com/*' => Http::response(['error_description' => 'Token expired'], 401)]);

    $this->getJson('/agentio/api/status')->assertOk()->assertJson([
        'youtrack' => ['configured' => true, 'ok' => false, 'error' => 'YouTrack GET issues failed with HTTP 401: Token expired'],
        'loop' => ['status' => 'running'],
    ]);
    $this->getJson('/agentio/api/sessions')->assertOk()->assertJsonPath('sessions.0.name', 'TP-2')->assertJsonPath('youtrack.ok', false);
    $this->getJson('/agentio/api/epics/TP-2')->assertOk()->assertJsonPath('epic', null)->assertJsonPath('youtrack.ok', false);
});

it('caches YouTrack responses between polls', function () {
    dashboardEnvironment();
    fakeYouTrack();

    $this->getJson('/agentio/api/pipeline')->assertOk();
    $sent = count(Http::recorded());
    app()->forgetInstance(YouTrackSource::class);
    $this->getJson('/agentio/api/pipeline')->assertOk();

    expect(count(Http::recorded()))->toBe($sent);
});

it('never shows the YouTrack token', function () {
    $logs = dashboardEnvironment();
    runningLoopLogs($logs);
    config(['agentio.youtrack.token' => 'perm-c2VjcmV0.dG9rZW4=']);
    app()->forgetInstance(Client::class);
    file_put_contents($logs.'/TP-2.log', json_encode(['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'curl -H "Authorization: Bearer perm-c2VjcmV0.dG9rZW4=" https://yt.example.com/api/issues']],
    ]]])."\n", FILE_APPEND);
    Http::fake(['yt.example.com/*' => Http::response(['error_description' => 'Bad token perm-c2VjcmV0.dG9rZW4='], 401)]);

    $sessions = $this->getJson('/agentio/api/sessions')->assertOk();
    $status = $this->getJson('/agentio/api/status')->assertOk();

    expect($sessions->getContent().$status->getContent())->not->toContain('perm-c2VjcmV0')
        ->and(collect($sessions->json('sessions.0.events'))->pluck('text')->last())->toContain('Bearer [redacted]')
        ->and($status->json('youtrack.error'))->toBe('YouTrack GET issues failed with HTTP 401: Bad token [redacted]');
});

it('requires authorization for the API', function () {
    dashboardEnvironment(configured: false);
    app()->detectEnvironment(fn (): string => 'production');

    $this->getJson('/agentio/api/status')->assertForbidden();
    $this->getJson('/agentio/api/sessions')->assertForbidden();
    $this->getJson('/agentio/api/epics/TP-2')->assertForbidden();
    $this->getJson('/agentio/api/issues/TP-2/log')->assertForbidden();
});
