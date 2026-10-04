<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;
use Symfony\Component\Process\Process;

/**
 * Run git in a directory of the test and return its output.
 */
function git(string $directory, string ...$arguments): string
{
    $process = new Process(['git', ...$arguments], $directory);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * A host project that is a git repository: main with README.md, and the branch epic/XY-2-avatar with one commit
 * adding app/Avatar.php and changing README.md.
 */
function reviewRepository(): string
{
    $project = hostProject();
    git($project, 'init', '-q', '-b', 'main');

    foreach (['user.email' => 'dev@example.com', 'user.name' => 'Dev', 'commit.gpgsign' => 'false'] as $key => $value) {
        git($project, 'config', $key, $value);
    }

    file_put_contents($project.'/README.md', "# App\n");
    file_put_contents($project.'/.gitignore', "/worktrees\n/logs\n.env\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'Initial');

    git($project, 'checkout', '-q', '-b', 'epic/XY-2-avatar');
    mkdir($project.'/app');
    file_put_contents($project.'/app/Avatar.php', "<?php\n\nfinal class Avatar {}\n");
    file_put_contents($project.'/README.md', "# App\n\nAvatars.\n");
    git($project, 'add', '.');
    git($project, 'commit', '-q', '-m', 'XY-5 Add the avatar');
    git($project, 'checkout', '-q', 'main');

    return $project;
}

/**
 * The package pointed at the repository, a fake YouTrack (REST for reads, MCP for writes) and a logs directory:
 * epic XY-2 in Review with the stories XY-3 (approved) and XY-4 (changes requested, then approved).
 *
 * @param  array<string, string>  $states  Issue id => Stage overrides
 */
function acceptanceEnvironment(string $project, array $states = []): FakeYouTrackMcp
{
    Sleep::fake();
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => 'local');
    // ValidateCsrfToken in Laravel 12, PreventRequestForgery in Laravel 13.
    test()->withoutMiddleware([ValidateCsrfToken::class, 'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery']);

    config([
        'agentio.youtrack.url' => FakeYouTrackMcp::URL,
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
        'agentio.youtrack.retries' => 0,
        'agentio.base_branch' => 'main',
        'agentio.merge_policy' => 'local-branch',
        'agentio.worktrees_path' => $project.'/worktrees',
        'cache.default' => 'array',
    ]);

    foreach ([Client::class, McpClient::class, IssueRepository::class, YouTrackSource::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    mkdir($project.'/logs');
    app()->instance(LoopState::class, new LoopState($project.'/logs', $project.'/logs/stop', 'UTC'));

    $state = fn (string $id, string $default): string => $states[$id] ?? $default;
    $mcp = (new FakeYouTrackMcp('XY'))->fake()
        ->issue('XY-2', 'Epic', $state('XY-2', 'Review'))
        ->issue('XY-3', 'Story', $state('XY-3', 'Review'), 'XY-2')
        ->issue('XY-4', 'Story', $state('XY-4', 'Review'), 'XY-2');

    $issues = [
        apiIssue('XY-2', ['Type' => 'Epic', 'Stage' => $state('XY-2', 'Review')], ['parent for' => ['XY-3', 'XY-4']], summary: '[EPIC] Аватар'),
        apiIssue('XY-3', ['Type' => 'Story', 'Stage' => $state('XY-3', 'Review')], ['subtask of' => ['XY-2'], 'parent for' => ['XY-5']], summary: '[STORY] Загрузка'),
        apiIssue('XY-4', ['Type' => 'Story', 'Stage' => $state('XY-4', 'Review')], ['subtask of' => ['XY-2']], summary: '[STORY] Показ'),
        apiIssue('XY-5', ['Type' => 'Task', 'Stage' => 'Done'], ['subtask of' => ['XY-3']], summary: '[TASK] Модель'),
        apiIssue('XY-9', ['Type' => 'Story', 'Stage' => 'Review'], summary: '[STORY] Чужая'),
    ];
    $comments = [
        'XY-2' => [['id' => 'c1', 'text' => "[AGENT:DONE]\n**Коммиты:** XY-5 Add the avatar\n**Полный прогон:** passed", 'created' => 1791013037440, 'author' => ['login' => 'agent']]],
        'XY-3' => [['id' => 'c2', 'text' => "[AGENT:DONE]\nВсе критерии подтверждены. Вердикт: APPROVED", 'created' => 1791013037440, 'author' => ['login' => 'agent']]],
        'XY-4' => [
            ['id' => 'c3', 'text' => "[AGENT:DECISION]\nCHANGES REQUESTED (раунд 1)", 'created' => 1791013037440, 'author' => ['login' => 'agent']],
            ['id' => 'c4', 'text' => "[AGENT:DONE]\nAPPROVED", 'created' => 1791013047440, 'author' => ['login' => 'agent']],
        ],
    ];

    Http::fake([FakeYouTrackMcp::URL.'/api/*' => function (Request $request) use ($issues, $comments) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/issues' => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : $issues),
            preg_match('#^/api/issues/([^/]+)/comments$#', $path, $match) === 1 => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : ($comments[$match[1]] ?? [])),
            default => Http::response(['error_description' => 'Not found'], 404),
        };
    }]);

    return $mcp;
}

/**
 * The epic worktree of XY-2, as agentio:worktree creates it.
 */
function epicWorktree(string $project): string
{
    git($project, 'worktree', 'add', '-q', $project.'/worktrees/XY-2', 'epic/XY-2-avatar');
    file_put_contents($project.'/worktrees/XY-2/.env', "APP_URL=http://localhost:8102\n");

    return $project.'/worktrees/XY-2';
}

it('shows what the epic branch changes and whether it can be merged', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project);
    epicWorktree($project);

    $response = $this->getJson('/agentio/api/epics/XY-2/review')->assertOk();

    expect($response->json('branch'))->toMatchArray([
        'name' => 'epic/XY-2-avatar',
        'merged' => false,
        'ahead' => 1,
        'behind' => 0,
        'added' => 5,
        'deleted' => 0,
        'worktree' => ['path' => $project.'/worktrees/XY-2', 'exists' => true, 'url' => 'http://localhost:8102'],
    ])
        ->and($response->json('branch.commits.0.subject'))->toBe('XY-5 Add the avatar')
        ->and($response->json('branch.files'))->toBe([
            ['path' => 'README.md', 'status' => 'M', 'added' => 2, 'deleted' => 0],
            ['path' => 'app/Avatar.php', 'status' => 'A', 'added' => 3, 'deleted' => 0],
        ])
        ->and(array_column($response->json('checks'), 'ok', 'key'))->toMatchArray([
            'state' => true, 'session' => true, 'worktree' => true, 'checkout' => true, 'clean' => true,
        ])
        ->and($response->json('canAccept'))->toBeTrue()
        ->and($response->json('base'))->toBe('main')
        ->and($response->json('actions'))->toBeTrue()
        ->and($response->json('summary.body'))->toContain('**Полный прогон:** passed')
        ->and(array_column($response->json('stories'), 'verdict', 'id'))->toBe(['XY-3' => 'APPROVED', 'XY-4' => 'APPROVED']);
});

it('returns the diff of one changed file', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project);

    $response = $this->getJson('/agentio/api/epics/XY-2/diff?file=app/Avatar.php')
        ->assertOk()
        ->assertJsonPath('path', 'app/Avatar.php')
        ->assertJsonPath('truncated', false);

    expect($response->json('diff'))->toStartWith('diff --git a/app/Avatar.php b/app/Avatar.php')
        ->toEndWith("--- /dev/null\n+++ b/app/Avatar.php\n@@ -0,0 +1,3 @@\n+<?php\n+\n+final class Avatar {}\n");

    $this->getJson('/agentio/api/epics/XY-2/diff?file=.gitignore')->assertNotFound();
    $this->getJson('/agentio/api/epics/XY-2/diff?file=--output=/tmp/x')->assertNotFound();
});

it('reports an epic without a branch', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project);

    $this->getJson('/agentio/api/epics/XY-7/review')
        ->assertOk()
        ->assertJsonPath('branch', null)
        ->assertJsonPath('checks', [])
        ->assertJsonPath('canAccept', false);
});

it('merges the epic, removes its worktree and closes the stories and the epic', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    $worktree = epicWorktree($project);

    $this->getJson('/agentio/api/epics/XY-2/review')->assertOk();

    $response = $this->postJson('/agentio/api/epics/XY-2/accept', ['removeWorktree' => true, 'close' => true])
        ->assertOk()
        ->assertJsonPath('merged', true)
        ->assertJsonPath('worktreeRemoved', true)
        ->assertJsonPath('branchDeleted', false)
        ->assertJsonPath('closed', ['XY-3', 'XY-4', 'XY-2'])
        ->assertJsonPath('warnings', []);

    expect(git($project, 'log', '-1', '--format=%s %P'))->toStartWith("Merge branch 'epic/XY-2-avatar'")
        ->and(git($project, 'rev-parse', '--short', 'HEAD'))->toBe($response->json('commit'))
        ->and(file_exists($project.'/app/Avatar.php'))->toBeTrue()
        ->and(is_dir($worktree))->toBeFalse()
        ->and(git($project, 'branch', '--list', 'epic/*'))->toBe('epic/XY-2-avatar')
        ->and(array_map(fn (array $issue): ?string => $issue['fields']['Stage'], $mcp->issues))->toBe(['XY-2' => 'Done', 'XY-3' => 'Done', 'XY-4' => 'Done'])
        ->and($mcp->comments['XY-2'][0]['text'])->toBe('Эпик принят в панели agentio: ветка `epic/XY-2-avatar` слита в `main` (`'.$response->json('commit').'`).');

    // The dashboard forgot its cached YouTrack answers: the next read goes to YouTrack again.
    $before = count(Http::recorded(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/issues')));
    $this->getJson('/agentio/api/epics/XY-2')->assertOk();

    expect(count(Http::recorded(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/issues'))))->toBeGreaterThan($before);
});

it('deletes the branch and keeps the epic open when a story has not passed review', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project, ['XY-4' => 'Blocked']);

    $this->postJson('/agentio/api/epics/XY-2/accept', ['deleteBranch' => true])
        ->assertOk()
        ->assertJsonPath('worktreeRemoved', false)
        ->assertJsonPath('branchDeleted', true)
        ->assertJsonPath('closed', ['XY-3'])
        ->assertJsonPath('warnings', ['Эпик оставлен в Review: не все истории прошли ревью — XY-4 (Blocked).']);

    expect(git($project, 'branch', '--list', 'epic/*'))->toBe('')
        ->and($mcp->issues['XY-2']['fields']['Stage'])->toBe('Review');
});

it('only closes the issues when the base branch already has the epic', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    git($project, 'merge', '-q', '--no-ff', '--no-edit', 'epic/XY-2-avatar');
    $head = git($project, 'rev-parse', 'HEAD');

    $this->getJson('/agentio/api/epics/XY-2/review')
        ->assertJsonPath('branch.merged', true)
        ->assertJsonPath('canAccept', true);

    $this->postJson('/agentio/api/epics/XY-2/accept')
        ->assertOk()
        ->assertJsonPath('merged', false)
        ->assertJsonPath('commit', null);

    expect(git($project, 'rev-parse', 'HEAD'))->toBe($head)
        ->and($mcp->comments['XY-2'][0]['text'])->toContain('уже слита в `main`');
});

it('refuses to merge when the main checkout is not ready', function (Closure $prepare, string $detail) {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    $prepare($project);
    $head = git($project, 'rev-parse', 'HEAD');

    $this->getJson('/agentio/api/epics/XY-2/review')->assertJsonPath('canAccept', false);

    $response = $this->postJson('/agentio/api/epics/XY-2/accept')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Эпик сейчас нельзя принять.');

    expect(implode("\n", $response->json('details')))->toContain($detail)
        ->and(git($project, 'rev-parse', 'HEAD'))->toBe($head)
        ->and($mcp->callsOf('update_issue'))->toBe([]);
})->with([
    'uncommitted changes' => [fn (string $project) => file_put_contents($project.'/README.md', "changed\n"), 'В главном каталоге нет незакоммиченных изменений — M README.md'],
    'another branch' => [fn (string $project) => git($project, 'checkout', '-q', '-b', 'feature'), 'Главный каталог на ветке main — сейчас: feature'],
    'uncommitted work in the worktree' => [fn (string $project) => file_put_contents(epicWorktree($project).'/app/Avatar.php', 'wip'), 'В worktree эпика всё закоммичено — M app/Avatar.php'],
    'a running session' => [fn (string $project) => file_put_contents($project.'/logs/XY-2.pid', (string) getmypid()), 'Сессия агента по эпику не запущена'],
]);

it('refuses an epic that is not in Review', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project, ['XY-2' => 'In Progress']);

    $this->postJson('/agentio/api/epics/XY-2/accept')
        ->assertStatus(409)
        ->assertJsonPath('details', ['Эпик в статусе Review — сейчас: In Progress']);
});

it('aborts a conflicting merge and leaves the main checkout as it was', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    file_put_contents($project.'/README.md', "# Another app\n");
    git($project, 'commit', '-q', '-am', 'Rename');
    $head = git($project, 'rev-parse', 'HEAD');

    $this->postJson('/agentio/api/epics/XY-2/accept')
        ->assertStatus(409)
        ->assertJsonPath('details', ['README.md']);

    expect(git($project, 'rev-parse', 'HEAD'))->toBe($head)
        ->and(git($project, 'status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and(file_exists($project.'/.git/MERGE_HEAD'))->toBeFalse()
        ->and($mcp->callsOf('update_issue'))->toBe([]);
});

it('sends the epic back with a task in the story', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    $mcp->on('create_issue', fn (array $arguments): array => ['id' => '2-77', 'idReadable' => 'XY-20']);

    $this->postJson('/agentio/api/epics/XY-2/rework', ['story' => 'XY-4', 'remark' => "Аватар не обрезается до квадрата.\nСм. ProfileController."])
        ->assertOk()
        ->assertJsonPath('task', 'XY-20')
        ->assertJsonPath('warnings', []);

    $created = $mcp->callsOf('create_issue')[0];

    expect($created)->toMatchArray([
        'project' => 'XY',
        'summary' => '[TASK] Review: Аватар не обрезается до квадрата.',
        'parentIssue' => 'XY-4',
        'customFields' => ['Type' => 'Task', 'Stage' => 'Ready'],
    ])
        ->and($created['description'])->toContain("## Что сделать\nАватар не обрезается до квадрата.\nСм. ProfileController.")
        ->and($mcp->issues['XY-4']['fields']['Stage'])->toBe('Ready')
        ->and($mcp->issues['XY-2']['fields']['Stage'])->toBe('Ready')
        ->and($mcp->issues['XY-3']['fields']['Stage'])->toBe('Review')
        ->and($mcp->comments['XY-4'][0]['text'])->toStartWith('Замечание при приёмке эпика XY-2 (панель agentio), задача XY-20:')
        ->and($mcp->comments['XY-2'][0]['text'])->toBe('Эпик возвращён на доработку в панели agentio: замечание к XY-4, задача XY-20.')
        ->and(git($project, 'branch', '--list', 'epic/*'))->toBe('epic/XY-2-avatar');
});

it('reads the id of the created task from a text answer', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project)->on('create_issue', fn (array $arguments): string => 'Issue XY-21 created: '.FakeYouTrackMcp::URL.'/issue/XY-21');

    $this->postJson('/agentio/api/epics/XY-2/rework', ['story' => 'XY-3', 'remark' => 'Нет теста на пустой файл'])
        ->assertOk()
        ->assertJsonPath('task', 'XY-21');
});

it('validates a rework request', function (array $payload, int $status) {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);

    $this->postJson('/agentio/api/epics/XY-2/rework', $payload)->assertStatus($status);

    expect($mcp->callsOf('create_issue'))->toBe([]);
})->with([
    'no remark' => [['story' => 'XY-3', 'remark' => '  '], 422],
    'no story' => [['remark' => 'Исправить'], 422],
    'a story of another epic' => [['story' => 'XY-9', 'remark' => 'Исправить'], 422],
    'not a story' => [['story' => 'XY-5', 'remark' => 'Исправить'], 422],
]);

it('keeps the dashboard read-only when actions are disabled', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    config(['agentio.ui.actions' => false]);

    $this->getJson('/agentio/api/epics/XY-2/review')->assertJsonPath('actions', false);
    $this->postJson('/agentio/api/epics/XY-2/accept')->assertForbidden();
    $this->postJson('/agentio/api/epics/XY-2/rework', ['story' => 'XY-3', 'remark' => 'x'])->assertForbidden();

    expect(git($project, 'log', '--oneline', 'main'))->not->toContain('Merge')
        ->and($mcp->calls)->toBe([]);
});

it('rejects an action without the CSRF token of the session', function () {
    $project = reviewRepository();
    $mcp = acceptanceEnvironment($project);
    app()->forgetInstance(ValidateCsrfToken::class);
    app()->forgetInstance('Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery');

    $this->postJson('/agentio/api/epics/XY-2/accept')->assertStatus(419);

    $this->withSession(['_token' => 'csrf-token'])
        ->postJson('/agentio/api/epics/XY-2/accept', [], ['X-CSRF-TOKEN' => 'csrf-token'])
        ->assertOk();

    expect($mcp->issues['XY-2']['fields']['Stage'])->toBe('Done');
});

it('answers 503 when YouTrack is not configured', function () {
    $project = reviewRepository();
    acceptanceEnvironment($project);
    config(['agentio.youtrack.token' => null]);
    app()->forgetInstance(IssueRepository::class);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(McpClient::class);

    $this->postJson('/agentio/api/epics/XY-2/accept')->assertStatus(503);
});
