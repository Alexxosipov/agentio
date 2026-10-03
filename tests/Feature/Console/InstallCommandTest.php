<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['agentio.youtrack.url' => null, 'agentio.youtrack.token' => null, 'agentio.youtrack.project' => 'TP']);
});

/**
 * Every file under a directory, relative path => content.
 *
 * @return array<string, string>
 */
function installedFilesIn(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($directory) + 1)] = (string) file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

/**
 * A stateful fake of the YouTrack REST API: a project with the given custom fields, bundles, tags, saved
 * searches and articles. Created entities are added to the state.
 *
 * @param  array<string, mixed>  $state
 */
function fakeYouTrackAdminApi(array $state = []): void
{
    $state = [
        'project' => ['id' => '0-9', 'shortName' => 'XY', 'name' => 'Example'],
        'projectFields' => [],
        'bundles' => ['state' => [], 'enum' => []],
        'fields' => [],
        'tags' => [],
        'queries' => [],
        'articles' => [],
        ...$state,
    ];
    $sequence = 100;

    config(['agentio.youtrack.url' => 'https://yt.example.com', 'agentio.youtrack.token' => 'secret-token']);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(IssueRepository::class);

    Http::fake(function (Request $request) use (&$state, &$sequence) {
        $path = (string) preg_replace('#^https://yt\.example\.com/api/#', '', strtok($request->url(), '?') ?: '');
        $get = $request->method() === 'GET';
        $body = $request->data();
        $page = fn (array $items) => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : array_values($items));

        return match (true) {
            $get && $path === 'admin/projects' => $page([$state['project']]),
            $get && $path === 'admin/projects/0-9/customFields' => $page($state['projectFields']),
            $get && str_starts_with($path, 'admin/customFieldSettings/bundles/') => $page($state['bundles'][substr($path, 34)] ?? []),
            $get && $path === 'admin/customFieldSettings/customFields' => $page($state['fields']),
            $get && $path === 'tags' => $page($state['tags']),
            $get && $path === 'savedQueries' => $page($state['queries']),
            $get && $path === 'articles' => $page($state['articles']),
            $path === 'articles' => (function () use (&$state, $body) {
                $number = count($state['articles']) + 1;
                $article = ['id' => '186-'.$number, 'idReadable' => 'XY-A-'.$number, 'summary' => $body['summary'], 'project' => ['shortName' => 'XY']];
                $state['articles'][] = $article;

                return Http::response($article);
            })(),
            default => Http::response(['id' => 'new-'.(++$sequence), 'name' => $body['name'] ?? null, 'values' => $body['values'] ?? []]),
        };
    });
}

/**
 * @return list<Request>
 */
function youTrackWrites(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() !== 'GET')->map(fn (array $pair): Request => $pair[0])->all();
}

it('installs the stubs with the placeholders rendered', function () {
    $project = hostProject();
    mkdir($project.'/.git');

    $this->artisan('agentio:install', ['--project' => 'XY', '--base-branch' => 'develop', '--merge-policy' => 'pull-request'])
        ->expectsOutputToContain('Installing agentio: YouTrack project XY, base branch develop, merge policy pull-request.')
        ->expectsOutputToContain('Next steps')
        ->assertSuccessful();

    $files = installedFilesIn($project);

    expect(array_keys($files))->toContain(
        '.claude/skills/youtrack-workflow/SKILL.md',
        '.claude/skills/work-epic/SKILL.md',
        '.claude/agents/task-developer.md',
        '.claude/hooks/guard-bash.php',
        '.claude/settings.json',
        '.claude/agent-settings.json',
        '.claude/agents-mcp.json',
        'scripts/yt.php',
        'scripts/agent-loop.sh',
        'scripts/php/testing.ini',
        'docs/AUTONOMOUS_WORKFLOW.md',
        'CLAUDE.md',
        '.gitignore',
        '.env.example',
        'config/agentio.php',
        '.agentio.json',
    );

    foreach ($files as $path => $content) {
        expect($content)->not->toMatch('/\{\{(project|base_branch|merge_policy|kb\.[a-z0-9_.]+)\}\}/', $path);
    }

    expect($files['scripts/yt.php'])->toContain("define('PROJECT', setting('AGENTIO_PROJECT') ?: 'XY');")
        ->and($files['scripts/agent-loop.sh'])->toContain('BASE_BRANCH="${BASE_BRANCH:-develop}"')
        ->and($files['.claude/skills/youtrack-workflow/SKILL.md'])->toContain('# YouTrack workflow (проект `XY`)', '«Обзор продукта»')
        ->and($files['CLAUDE.md'])->toStartWith('<!-- agentio:start -->')->toContain('MERGE_POLICY: pull-request', 'Push в `develop`')
        ->and($files['.gitignore'])->toContain("/.agent-stop\n/storage/logs/agents\n")
        ->and($files['.env.example'])->toContain("YOUTRACK_URL=\nYOUTRACK_TOKEN=\nAGENTIO_PROJECT=XY\n")
        ->and(is_executable($project.'/scripts/agent-loop.sh'))->toBeTrue()
        ->and(is_executable($project.'/scripts/run-tests.sh'))->toBeTrue()
        ->and(json_decode($files['.claude/agents-mcp.json'], true)['mcpServers'])->toHaveKey('youtrack')->not->toHaveKey('laravel-boost');

    $manifest = Manifest::load($project);

    expect($manifest->project)->toBe('XY')
        ->and($manifest->baseBranch)->toBe('develop')
        ->and($manifest->files)->toHaveKey('scripts/agent-loop.sh', hash('sha256', $files['scripts/agent-loop.sh']));
});

it('is idempotent', function () {
    $project = hostProject();

    $this->artisan('agentio:install', ['--project' => 'XY'])->assertSuccessful();
    $before = installedFilesIn($project);

    $this->artisan('agentio:install')
        ->expectsOutputToContain('YouTrack project XY')
        ->doesntExpectOutputToContain('created')
        ->doesntExpectOutputToContain('updated')
        ->assertSuccessful();

    expect(installedFilesIn($project))->toBe($before);
});

it('keeps locally edited files unless forced and updates files nobody edited', function () {
    $project = hostProject();
    $this->artisan('agentio:install', ['--project' => 'XY'])->assertSuccessful();
    $stub = (string) file_get_contents($project.'/scripts/run-tests.sh');

    file_put_contents($project.'/scripts/run-tests.sh', "#!/usr/bin/env bash\necho edited\n");

    $this->artisan('agentio:install')
        ->expectsOutputToContain('scripts/run-tests.sh: differs from the stub (edited locally?); --force overwrites it')
        ->assertSuccessful();

    expect(file_get_contents($project.'/scripts/run-tests.sh'))->toBe("#!/usr/bin/env bash\necho edited\n")
        ->and(Manifest::load($project)->files['scripts/run-tests.sh'])->toBe(hash('sha256', $stub));

    $this->artisan('agentio:install', ['--force' => true])->expectsOutputToContain('overwritten (--force)')->assertSuccessful();

    expect(file_get_contents($project.'/scripts/run-tests.sh'))->toBe($stub);

    // An older version of the stub, installed by a previous release: still pristine, so it is updated.
    $old = "#!/usr/bin/env bash\necho old release\n";
    file_put_contents($project.'/scripts/run-tests.sh', $old);
    $manifest = Manifest::load($project);
    $manifest->with(files: [...$manifest->files, 'scripts/run-tests.sh' => hash('sha256', $old)])->save($project);

    $this->artisan('agentio:install')->expectsOutputToContain('new version of the stub')->assertSuccessful();

    expect(file_get_contents($project.'/scripts/run-tests.sh'))->toBe($stub);
});

it('restores the executable bit of an unchanged script', function () {
    $project = hostProject();
    $this->artisan('agentio:install', ['--project' => 'XY'])->assertSuccessful();
    chmod($project.'/scripts/agent-loop.sh', 0644);

    $this->artisan('agentio:install')->expectsOutputToContain('made executable')->assertSuccessful();

    expect(is_executable($project.'/scripts/agent-loop.sh'))->toBeTrue();
});

it('merges .claude/settings.json with the settings of the project', function () {
    $project = hostProject();
    mkdir($project.'/.claude');
    file_put_contents($project.'/.claude/settings.json', json_encode([
        'model' => 'opus',
        'permissions' => ['allow' => ['Bash(make *)', 'Read'], 'deny' => ['Bash(rm -rf /)'], 'additionalDirectories' => ['/srv/shared']],
        'hooks' => ['PostToolUse' => [['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'lint']]]]],
        'enabledMcpjsonServers' => ['sentry'],
    ]));

    $this->artisan('agentio:install', ['--project' => 'XY'])
        ->expectsOutputToContain('merged: permissions, hooks, MCP servers')
        ->assertSuccessful();

    $settings = json_decode((string) file_get_contents($project.'/.claude/settings.json'), true);

    expect($settings['model'])->toBe('opus')
        ->and($settings['permissions']['additionalDirectories'])->toBe(['/srv/shared'])
        ->and($settings['permissions']['allow'])->toContain('Bash(make *)', 'Bash(php scripts/yt.php *)', 'Read')
        ->and(array_count_values($settings['permissions']['allow'])['Read'])->toBe(1)
        ->and($settings['permissions']['deny'])->toContain('Bash(rm -rf /)', 'Bash(git push --force*)', 'Bash(git push origin main*)')
        ->and(array_count_values($settings['permissions']['deny'])['Bash(git push origin main*)'])->toBe(1)
        ->and($settings['hooks']['PostToolUse'][0]['hooks'][0]['command'])->toBe('lint')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toContain('.claude/hooks/guard-bash.php')
        ->and($settings['enabledMcpjsonServers'])->toBe(['sentry']);

    $this->artisan('agentio:install')->assertSuccessful();

    expect(json_decode((string) file_get_contents($project.'/.claude/settings.json'), true))->toBe($settings);
});

it('leaves an invalid settings.json alone', function () {
    $project = hostProject();
    mkdir($project.'/.claude');
    file_put_contents($project.'/.claude/settings.json', '{ invalid');

    $this->artisan('agentio:install', ['--project' => 'XY'])->expectsOutputToContain('not valid JSON, left as is')->assertSuccessful();

    expect(file_get_contents($project.'/.claude/settings.json'))->toBe('{ invalid');
});

it('configures the Laravel Boost MCP server only when the project uses Boost', function () {
    $project = hostProject();
    file_put_contents($project.'/composer.json', json_encode(['require-dev' => ['laravel/boost' => '^2.0']]));

    $this->artisan('agentio:install', ['--project' => 'XY'])->assertSuccessful();

    expect(json_decode((string) file_get_contents($project.'/.claude/agents-mcp.json'), true)['mcpServers'])->toHaveKeys(['youtrack', 'laravel-boost'])
        ->and(json_decode((string) file_get_contents($project.'/.claude/settings.json'), true)['enabledMcpjsonServers'])->toBe(['laravel-boost']);
});

it('puts the agentio block on top of an existing CLAUDE.md and replaces it on reinstall', function () {
    $project = hostProject();
    file_put_contents($project.'/CLAUDE.md', "# My project\n\nOur own rules.\n");

    $this->artisan('agentio:install', ['--project' => 'XY'])->expectsOutputToContain('agentio block inserted at the top')->assertSuccessful();

    $markdown = (string) file_get_contents($project.'/CLAUDE.md');

    expect($markdown)->toStartWith("<!-- agentio:start -->\n")
        ->toContain("<!-- agentio:end -->\n\n# My project\n\nOur own rules.\n", 'MERGE_POLICY: local-branch');

    $this->artisan('agentio:install', ['--merge-policy' => 'auto-merge'])->expectsOutputToContain('agentio block replaced')->assertSuccessful();

    $markdown = (string) file_get_contents($project.'/CLAUDE.md');

    expect($markdown)->toContain('MERGE_POLICY: auto-merge', "# My project\n\nOur own rules.\n")
        ->not->toContain('MERGE_POLICY: local-branch')
        ->and(substr_count($markdown, '<!-- agentio:start -->'))->toBe(1);

    // Without --merge-policy the policy written in CLAUDE.md is kept.
    $this->artisan('agentio:install')->expectsOutputToContain('merge policy auto-merge')->assertSuccessful();
});

it('does not duplicate existing .gitignore and .env.example entries', function () {
    $project = hostProject();
    file_put_contents($project.'/.gitignore', ".agent-stop\n/vendor\n");
    file_put_contents($project.'/.env.example', "APP_NAME=Laravel\n# YOUTRACK_URL=https://example.youtrack.cloud\n");
    mkdir($project.'/config');
    file_put_contents($project.'/config/agentio.php', "<?php return ['custom' => true];\n");

    $this->artisan('agentio:install', ['--project' => 'XY'])->expectsOutputToContain('already published')->assertSuccessful();

    expect(file_get_contents($project.'/.gitignore'))->toBe(".agent-stop\n/vendor\n\n# agentio\n/storage/logs/agents\n")
        ->and(file_get_contents($project.'/.env.example'))->toBe("APP_NAME=Laravel\n# YOUTRACK_URL=https://example.youtrack.cloud\n\n# agentio\nYOUTRACK_TOKEN=\nAGENTIO_PROJECT=XY\n")
        ->and(file_get_contents($project.'/config/agentio.php'))->toBe("<?php return ['custom' => true];\n");
});

it('only shows the plan in a dry run', function () {
    $project = hostProject();

    $this->artisan('agentio:install', ['--project' => 'XY', '--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing is changed.')
        ->expectsOutputToContain('will be created')
        ->assertSuccessful();

    expect(installedFilesIn($project))->toBe([]);
});

it('rejects an invalid merge policy or project key', function (array $options, string $message) {
    hostProject();

    $this->artisan('agentio:install', $options)->expectsOutputToContain($message)->assertFailed();
})->with([
    'merge policy option' => [['--merge-policy' => 'yolo'], 'Invalid merge policy'],
    'project key' => [['--project' => 'not a key'], 'Invalid YouTrack project short name'],
]);

it('rejects an invalid merge policy in the config', function () {
    hostProject();
    config(['agentio.merge_policy' => 'sometimes']);

    $this->artisan('agentio:install')->expectsOutputToContain('Invalid merge policy')->assertFailed();
});

it('reports missing preconditions with hints', function () {
    hostProject();
    config(['agentio.claude_binary' => '/nonexistent/claude']);

    $this->artisan('agentio:install', ['--project' => 'XY', '--dry-run' => true])
        ->expectsOutputToContain('Fix: git repository')
        ->expectsOutputToContain('Fix: Claude Code CLI (/nonexistent/claude)')
        ->expectsOutputToContain('Fix: YOUTRACK_URL')
        ->expectsOutputToContain('Set AGENTIO_PROJECT=XY in .env or remove it (the config says TP, the files are installed for XY)')
        ->assertSuccessful();
});

it('does not ask to set the project or the base branch when the config leaves them to .agentio.json', function () {
    hostProject();
    config(['agentio.youtrack.project' => null, 'agentio.base_branch' => null]);

    $this->artisan('agentio:install', ['--project' => 'XY', '--base-branch' => 'develop', '--dry-run' => true])
        ->doesntExpectOutputToContain('Set AGENTIO_PROJECT')
        ->doesntExpectOutputToContain('Set AGENTIO_BASE_BRANCH')
        ->assertSuccessful();

    config(['agentio.base_branch' => 'main']);

    $this->artisan('agentio:install', ['--project' => 'XY', '--base-branch' => 'develop', '--dry-run' => true])
        ->expectsOutputToContain('Set AGENTIO_BASE_BRANCH=develop in .env or remove it (the config says main)')
        ->assertSuccessful();
});

it('sets up an empty YouTrack project and records the knowledge base ids', function () {
    $project = hostProject();
    fakeYouTrackAdminApi();

    $this->artisan('agentio:install', ['--project' => 'XY', '--youtrack' => true])
        ->expectsOutputToContain('YouTrack project setup')
        ->assertSuccessful();

    $posts = collect(youTrackWrites())->map(fn (Request $request): string => $request->method().' '.strtok(substr($request->url(), strlen('https://yt.example.com/api/')), '?'));

    expect($posts->filter(fn (string $post): bool => $post === 'POST admin/customFieldSettings/bundles/state'))->toHaveCount(2)
        ->and($posts)->toContain('POST admin/customFieldSettings/bundles/enum', 'POST admin/customFieldSettings/customFields', 'POST admin/projects/0-9/customFields', 'POST tags', 'POST savedQueries')
        ->and($posts->filter(fn (string $post): bool => $post === 'POST articles'))->toHaveCount(count(KnowledgeBase::ARTICLES));

    Http::assertSent(fn (Request $request): bool => str_ends_with(strtok($request->url(), '?') ?: '', 'bundles/state')
        && ($request->data()['name'] ?? null) === 'XY States'
        && $request['values'][6] === ['name' => 'Done', 'isResolved' => true]
        && $request['values'][0] === ['name' => 'Backlog', 'isResolved' => false]);
    Http::assertSent(fn (Request $request): bool => str_ends_with(strtok($request->url(), '?') ?: '', 'admin/projects/0-9/customFields')
        && ($request->data()['$type'] ?? null) === 'StateProjectCustomField' && $request['bundle']['$type'] === 'StateBundle');
    Http::assertSent(fn (Request $request): bool => str_ends_with(strtok($request->url(), '?') ?: '', 'savedQueries')
        && ($request->data()['name'] ?? null) === 'XY: готовые эпики' && $request['query'] === 'project: XY Type: Epic State: Ready tag: -{agent-claimed}');

    $articles = collect(youTrackWrites())->filter(fn (Request $request): bool => str_contains($request->url(), '/api/articles'))->values();
    $adr = $articles->first(fn (Request $request): bool => $request['summary'] === 'ADR');
    $adr001 = $articles->first(fn (Request $request): bool => $request['summary'] === 'ADR-001: Базовые архитектурные решения');
    $guide = $articles->first(fn (Request $request): bool => $request['summary'] === 'Руководство по автоматизации');

    expect($articles->first()->data())->not->toHaveKey('parentArticle')
        ->and($adr['parentArticle'])->toBe(['id' => '186-4'])
        ->and($adr001['parentArticle'])->toBe(['id' => '186-7'])
        ->and($guide['parentArticle'])->toBe(['id' => '186-9'])
        ->and($guide['content'])->toContain('# Руководство по автоматизации разработки', 'проект `XY`')
        ->and($guide['project'])->toBe(['id' => '0-9']);

    $kb = Manifest::load($project)->kb;

    expect($kb)->toHaveCount(count(KnowledgeBase::ARTICLES))
        ->and(file_get_contents($project.'/.claude/skills/youtrack-workflow/SKILL.md'))->toContain('| Обзор продукта | '.$kb['overview'].' |');
});

it('only plans the YouTrack setup in a dry run', function () {
    hostProject();
    fakeYouTrackAdminApi();

    $this->artisan('agentio:install', ['--project' => 'XY', '--youtrack' => true, '--dry-run' => true])
        ->expectsOutputToContain('will create')
        ->assertSuccessful();

    expect(youTrackWrites())->toBe([]);
});

it('extends existing fields and keeps what the project already has', function () {
    $project = hostProject();
    $articles = [];
    $id = 0;

    foreach (KnowledgeBase::ARTICLES as [$title]) {
        $id++;
        $articles[] = ['id' => '186-'.$id, 'idReadable' => 'XY-A-'.$id, 'summary' => $title, 'project' => ['shortName' => 'XY']];
    }

    fakeYouTrackAdminApi([
        'projectFields' => [
            ['field' => ['name' => 'State'], 'bundle' => ['id' => 'b-state', 'name' => 'Workflow', '$type' => 'StateBundle']],
            ['field' => ['name' => 'Type'], 'bundle' => ['id' => 'b-type', 'name' => 'Types', '$type' => 'EnumBundle']],
            ['field' => ['name' => 'Stage'], 'bundle' => ['id' => 'b-stage', 'name' => 'Stages', '$type' => 'OwnedBundle']],
        ],
        'bundles' => [
            'state' => [['id' => 'b-state', 'name' => 'Workflow', 'values' => [['name' => 'Backlog'], ['name' => 'In Progress'], ['name' => 'Done'], ['name' => 'Wontfix']]]],
            'enum' => [['id' => 'b-type', 'name' => 'Types', 'values' => [['name' => 'Idea'], ['name' => 'Epic'], ['name' => 'Story'], ['name' => 'Task']]]],
        ],
        'tags' => [['id' => '1', 'name' => 'idea'], ['id' => '2', 'name' => 'agent-claimed']],
        'queries' => array_map(fn (string $name, string $query): array => ['name' => $name, 'query' => $query.' sort by: updated desc'], array_keys(YouTrackSetup::savedSearches('XY')), YouTrackSetup::savedSearches('XY')),
        'articles' => $articles,
    ]);

    $this->artisan('agentio:install', ['--project' => 'XY', '--youtrack' => true])
        ->expectsOutputToContain('add value Analysis')
        ->expectsOutputToContain('own sorting kept')
        ->expectsOutputToContain('attached with a OwnedBundle bundle, expected StateBundle; left as is')
        ->assertSuccessful();

    $added = collect(youTrackWrites())->map(fn (Request $request): string => $request->url().' '.json_encode($request->data()));

    expect($added)->toHaveCount(4)
        ->and($added->every(fn (string $write): bool => str_contains($write, 'bundles/state/b-state/values')))->toBeTrue()
        ->and($added->implode(' '))->toContain('"name":"Analysis"', '"name":"Ready"', '"name":"Review"', '"name":"Blocked"')
        ->and(Manifest::load($project)->kb['adr.001'])->toBe('XY-A-8');
});

it('attaches existing global fields and bundles found by name', function () {
    hostProject();
    fakeYouTrackAdminApi([
        'bundles' => [
            'state' => [['id' => 'b-1', 'name' => 'XY States', 'values' => array_map(fn (string $name): array => ['name' => $name], ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done'])]],
            'enum' => [],
        ],
        'fields' => [['id' => 'f-state', 'name' => 'State', 'fieldType' => ['id' => 'state[1]']], ['id' => 'f-type', 'name' => 'Type', 'fieldType' => ['id' => 'text[1]']]],
    ]);

    $this->artisan('agentio:install', ['--project' => 'XY', '--youtrack' => true])
        ->expectsOutputToContain('a global field of type text[1] exists, expected enum[1]; not attached')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'admin/projects/0-9/customFields')
        && ($request->data()['field'] ?? null) === ['id' => 'f-state'] && $request['bundle']['id'] === 'b-1');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'customFields') && ($request['name'] ?? null) === 'State');
});

it('fails when YouTrack is not configured or the project does not exist', function () {
    hostProject();

    $this->artisan('agentio:install', ['--project' => 'XY', '--youtrack' => true])
        ->expectsOutputToContain('--youtrack needs YOUTRACK_URL and YOUTRACK_TOKEN')
        ->assertFailed();

    fakeYouTrackAdminApi();

    $this->artisan('agentio:install', ['--project' => 'NOPE', '--youtrack' => true])
        ->expectsOutputToContain('YouTrack setup failed')
        ->assertFailed();
});
