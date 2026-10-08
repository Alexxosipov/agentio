<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Telegram\ActionRunner;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;

/**
 * A project with the bot set up and a fake YouTrack (REST for reads, MCP for writes): the epic XY-2 claimed by this
 * machine (story XY-3, task XY-5 in progress), the epic XY-10 that depends on XY-2, the epic XY-11 whose task XY-13
 * depends on a task of XY-10, and the accepted epic XY-12 that depended on XY-2.
 *
 * @param  array<string, string>  $states  Issue id => Stage overrides
 * @param  list<array<string, mixed>>  $epicComments  More REST comments of XY-2
 */
function pauseEnvironment(array $states = [], array $epicComments = [], bool $claimed = true): FakeYouTrackMcp
{
    $project = projectWithBot();
    Queue::fake();
    app()->detectEnvironment(fn (): string => 'local');
    // ValidateCsrfToken in Laravel 12, PreventRequestForgery in Laravel 13.
    test()->withoutMiddleware([ValidateCsrfToken::class, 'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery']);
    config([
        'agentio.youtrack.url' => FakeYouTrackMcp::URL,
        'agentio.youtrack.retries' => 0,
        'cache.default' => 'array',
    ]);

    foreach ([Client::class, McpClient::class, IssueRepository::class, YouTrackSource::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    mkdir($project.'/logs');
    app()->instance(LoopState::class, new LoopState($project.'/logs', $project.'/logs/stop', 'UTC'));

    $state = fn (string $id, string $default): string => $states[$id] ?? $default;
    $tags = $claimed ? ['agent-claimed'] : [];
    $mcp = (new FakeYouTrackMcp('XY'))->fake()->issue('XY-2', 'Epic', $state('XY-2', 'In Progress'), tags: $tags);

    $issues = [
        apiIssue('XY-2', ['Type' => 'Epic', 'Stage' => $state('XY-2', 'In Progress')], ['parent for' => ['XY-3'], 'is required for' => ['XY-10', 'XY-12']], $tags, '[EPIC] Аватар'),
        apiIssue('XY-3', ['Type' => 'Story', 'Stage' => 'In Progress'], ['subtask of' => ['XY-2'], 'parent for' => ['XY-5']]),
        apiIssue('XY-5', ['Type' => 'Task', 'Stage' => 'In Progress'], ['subtask of' => ['XY-3']], ['agent-claimed']),
        apiIssue('XY-10', ['Type' => 'Epic', 'Stage' => 'Ready'], ['depends on' => ['XY-2'], 'parent for' => ['XY-14']], summary: '[EPIC] Галерея'),
        apiIssue('XY-14', ['Type' => 'Story', 'Stage' => 'Ready'], ['subtask of' => ['XY-10'], 'parent for' => ['XY-15']]),
        apiIssue('XY-15', ['Type' => 'Task', 'Stage' => 'Ready'], ['subtask of' => ['XY-14'], 'is required for' => ['XY-13']]),
        apiIssue('XY-11', ['Type' => 'Epic', 'Stage' => 'Backlog'], ['parent for' => ['XY-16']], summary: '[EPIC] Лента'),
        apiIssue('XY-16', ['Type' => 'Story', 'Stage' => 'Backlog'], ['subtask of' => ['XY-11'], 'parent for' => ['XY-13']]),
        apiIssue('XY-13', ['Type' => 'Task', 'Stage' => 'Backlog'], ['subtask of' => ['XY-16'], 'depends on' => ['XY-15']]),
        apiIssue('XY-12', ['Type' => 'Epic', 'Stage' => 'Done'], ['depends on' => ['XY-2']], summary: '[EPIC] Старое'),
    ];
    $comments = [
        'XY-2' => [
            ...($claimed ? [['id' => 'c1', 'text' => "[AGENT:START]\nowner: `host:/w/XY-2`", 'created' => 1791013037440, 'author' => ['login' => 'agent']]] : []),
            ...$epicComments,
        ],
    ];

    Http::fake([FakeYouTrackMcp::URL.'/api/*' => function (Request $request) use ($issues, $comments) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/issues' => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : $issues),
            $path === '/api/activities' => Http::response([]),
            preg_match('#^/api/issues/([^/]+)/comments$#', $path, $match) === 1 => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : ($comments[$match[1]] ?? [])),
            default => Http::response(['error_description' => 'Not found'], 404),
        };
    }]);

    return $mcp;
}

it('pauses an epic softly, keeps its claim and names the epics waiting for it', function () {
    $mcp = pauseEnvironment();
    Process::fake();

    $this->artisan('agentio:pause', ['epic' => 'xy-2', '--reason' => 'Сначала платежи'])
        ->expectsOutputToContain('XY-2 is on hold.')
        ->expectsOutputToContain('Claim kept: host:/w/XY-2')
        ->expectsOutputToContain('XY-10 (Ready) waits for it: Галерея')
        ->expectsOutputToContain('XY-11 (Backlog) waits for it through XY-10: Лента')
        ->assertSuccessful();

    $comment = (string) end($mcp->comments['XY-2'])['text'];

    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('On Hold')
        ->and($mcp->issues['XY-2']['tags'])->toBe(['agent-claimed'])
        ->and($comment)->toStartWith("[AGENT:PAUSE]\n**Эпик поставлен на паузу** (разработчик, командная строка): Сначала платежи")
        ->and($comment)->toContain('**Stage до паузы:** In Progress', 'сохранён за `host:/w/XY-2`', '**Ждут этот эпик:** XY-10 (Ready), XY-11 (Backlog, через XY-10)')
        ->and($comment)->not->toContain('XY-12');

    Process::assertNothingRan();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->issue === 'XY-2'
        && str_contains($job->text, '⏸ Эпик XY-2 «Аватар» на паузе')
        && str_contains($job->text, 'Пока он на паузе, стоят и зависимые эпики')
        && str_contains($job->text, '• XY-11 «Лента» — Backlog, через XY-10'));
});

it('stops the session of the epic right away with --now', function () {
    $mcp = pauseEnvironment();
    Process::fake();
    // A live process stands for the session of the epic.
    $pid = getmypid();
    file_put_contents(app(LoopState::class)->logsPath().'/XY-2.pid', (string) $pid);

    $this->artisan('agentio:pause', ['epic' => 'XY-2', '--now' => true, '--json' => true])->assertSuccessful();

    Process::assertRan(fn ($process): bool => $process->command === ['kill', '-TERM', '--', '-'.$pid]);
    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('On Hold')
        ->and((string) end($mcp->comments['XY-2'])['text'])->toContain('**Режим:** немедленная — сессия эпика остановлена');
});

it('lifts the pause: a claimed epic goes back In Progress for its owner', function () {
    $mcp = pauseEnvironment(['XY-2' => 'On Hold'], [['id' => 'c2', 'text' => "[AGENT:PAUSE]\n**Stage до паузы:** Ready", 'created' => 1791013047440, 'author' => ['login' => 'agent']]]);

    $this->artisan('agentio:resume', ['epic' => 'XY-2'])
        ->expectsOutputToContain('XY-2: the pause is lifted, Stage In Progress (claim of host:/w/XY-2')
        ->assertSuccessful();

    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('In Progress')
        ->and((string) end($mcp->comments['XY-2'])['text'])->toStartWith("[AGENT:RESUME]\n**Пауза снята** (разработчик, командная строка): Stage → In Progress.");
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '▶️ Пауза эпика XY-2 «Аватар» снята'));
});

it('returns an epic without a claim to its Stage before the pause', function () {
    $mcp = pauseEnvironment(['XY-2' => 'On Hold'], [['id' => 'c2', 'text' => "[AGENT:PAUSE]\n**Режим:** мягкая\n**Stage до паузы:** Blocked\n**Захват:** нет.", 'created' => 1791013047440, 'author' => ['login' => 'agent']]], claimed: false);

    $this->artisan('agentio:resume', ['epic' => 'XY-2'])->assertSuccessful();

    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('Blocked');
});

it('refuses to pause an epic waiting for its acceptance, and a story', function () {
    $mcp = pauseEnvironment(['XY-2' => 'Review']);

    $this->artisan('agentio:pause', ['epic' => 'XY-2'])
        ->expectsOutputToContain('уже ждёт приёмки (Review)')
        ->assertFailed();
    $this->artisan('agentio:pause', ['epic' => 'XY-3'])
        ->expectsOutputToContain('XY-3 — не эпик')
        ->assertFailed();
    $this->artisan('agentio:resume', ['epic' => 'XY-2'])
        ->expectsOutputToContain('не на паузе')
        ->assertFailed();

    expect($mcp->callsOf('add_issue_comment'))->toBe([])
        ->and($mcp->callsOf('update_issue'))->toBe([]);
});

it('pauses and resumes an epic from the dashboard', function () {
    $mcp = pauseEnvironment();
    Process::fake();

    $paused = $this->postJson('/agentio/api/epics/XY-2/pause', ['now' => false])->assertOk();

    expect($paused->json('epic'))->toBe('XY-2')
        ->and($paused->json('previous'))->toBe('In Progress')
        ->and(array_column($paused->json('dependents'), 'id'))->toBe(['XY-10', 'XY-11'])
        ->and($paused->json('message'))->toContain('на паузе')
        ->and($mcp->issues['XY-2']['fields']['Stage'])->toBe('On Hold')
        ->and((string) end($mcp->comments['XY-2'])['text'])->toContain('(разработчик, панель agentio)');

    $this->getJson('/agentio/api/epics/XY-2')->assertOk()
        ->assertJsonPath('epic.dependents.0.id', 'XY-10')
        ->assertJsonPath('epic.dependents.1.via', 'XY-10');

    // The reads come from the REST API, where XY-2 is still In Progress.
    $this->postJson('/agentio/api/epics/XY-2/resume')->assertStatus(409)->assertJsonPath('message', 'Эпик XY-2 не на паузе (сейчас: In Progress).');
});

it('lifts the pause from the dashboard', function () {
    $mcp = pauseEnvironment(['XY-2' => 'On Hold']);

    $this->postJson('/agentio/api/epics/XY-2/resume')->assertOk()
        ->assertJsonPath('state', 'In Progress')
        ->assertJsonPath('claimed', true);

    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('In Progress');
});

it('refuses the pause in the dashboard when its actions are off', function () {
    pauseEnvironment();
    config(['agentio.ui.actions' => false]);

    $this->postJson('/agentio/api/epics/XY-2/pause')->assertForbidden();
    $this->postJson('/agentio/api/epics/XY-2/resume')->assertForbidden();
});

it('pauses and resumes an epic for the Telegram bot', function () {
    $mcp = pauseEnvironment();
    Process::fake();
    $runner = app(ActionRunner::class);

    [$line, $issue] = $runner->pause('XY-2', false, 'Сначала платежи');

    expect($issue)->toBe('XY-2')
        ->and($line)->toContain('⏸ Эпик XY-2 «Аватар» на паузе', '• XY-10 «Галерея» — Ready')
        ->and((string) end($mcp->comments['XY-2'])['text'])->toContain('(разработчик, Telegram): Сначала платежи')
        ->and($runner->pause(null, false, '')[0])->toContain('Не понял, какой эпик')
        ->and($runner->resume('XY-3')[0])->toContain('Не снял паузу с XY-3');
});
