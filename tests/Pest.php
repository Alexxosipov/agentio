<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Tests\TestCase;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\State;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(TestCase::class)
    // Every outside system is faked: a request of the HTTP client (YouTrack, the Telegram Bot API, the
    // transcription APIs) without Http::fake() and a process of the Process facade (composer, Claude Code,
    // Horizon) without Process::fake() fail the test.
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    })
    ->afterEach(fn () => Agentio::auth(null))
    ->in(__DIR__);

/**
 * An issue in the shape the YouTrack REST API returns it (Client::ISSUE_FIELDS).
 *
 * @param  array<string, string|null>  $fields
 * @param  array<string, list<string>>  $links  Relation (as seen from this issue) => issue ids
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function apiIssue(string $id, array $fields = [], array $links = [], array $tags = [], ?string $summary = null): array
{
    $directions = [
        'depends on' => ['INWARD', 'Depend', 'is required for', 'depends on'],
        'is required for' => ['OUTWARD', 'Depend', 'is required for', 'depends on'],
        'subtask of' => ['INWARD', 'Subtask', 'parent for', 'subtask of'],
        'parent for' => ['OUTWARD', 'Subtask', 'parent for', 'subtask of'],
        'relates to' => ['BOTH', 'Relates', 'relates to', ''],
    ];

    return [
        'idReadable' => $id,
        'summary' => $summary ?? 'Summary of '.$id,
        'created' => 1791010361184,
        'updated' => 1791027550675,
        'resolved' => null,
        'customFields' => array_map(
            fn (string $name, ?string $value): array => ['name' => $name, 'value' => $value === null ? null : ['name' => $value]],
            array_keys($fields),
            array_values($fields),
        ),
        'tags' => array_map(fn (string $tag): array => ['id' => 'tag-'.$tag, 'name' => $tag], $tags),
        'links' => array_map(function (string $relation, array $ids) use ($directions): array {
            [$direction, $name, $sourceToTarget, $targetToSource] = $directions[$relation];

            return [
                'direction' => $direction,
                'linkType' => ['name' => $name, 'sourceToTarget' => $sourceToTarget, 'targetToSource' => $targetToSource],
                'issues' => array_map(fn (string $linked): array => ['idReadable' => $linked], $ids),
            ];
        }, array_keys($links), array_values($links)),
    ];
}

/**
 * A readiness graph from compact specs: id => [type, state, parent?, dependsOn?, tags?].
 * "parent for" links are derived from the parents.
 *
 * @param  array<string, array{0: string, 1: string, 2?: string|null, 3?: list<string>, 4?: list<string>}>  $specs
 */
function graphOf(array $specs): ReadinessGraph
{
    $children = [];

    foreach ($specs as $id => $spec) {
        if (($spec[2] ?? null) !== null) {
            $children[$spec[2]][] = $id;
        }
    }

    $issues = [];

    foreach ($specs as $id => $spec) {
        $links = array_filter([
            'subtask of' => ($spec[2] ?? null) === null ? [] : [$spec[2]],
            'parent for' => $children[$id] ?? [],
            'depends on' => $spec[3] ?? [],
        ]);

        $issues[] = Issue::fromApi(apiIssue($id, ['Type' => $spec[0], 'Stage' => $spec[1]], $links, $spec[4] ?? []));
    }

    return new ReadinessGraph($issues);
}

/**
 * Run git in a directory of the test and return its output.
 */
function git(string $directory, string ...$arguments): string
{
    $process = new SymfonyProcess(['git', ...$arguments], $directory);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * A fresh temporary directory, removed after the test.
 */
function temporaryDirectory(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-'.Str::random(12);
    mkdir($directory, 0777, true);

    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));

    return $directory;
}

/**
 * A fresh temporary host project used as the application base path (config, storage and the installed
 * files live in it), removed with everything inside after the test.
 */
function hostProject(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-host-'.Str::random(12);
    mkdir($directory, 0777, true);

    app()->setBasePath($directory);

    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));

    return $directory;
}

/**
 * An agile board of the project XY: columns by the given field, a column per value, and swimlanes by a field
 * (null: no swimlanes).
 *
 * @param  list<string>|null  $columns  The column values, by default the stages of the cycle
 * @return array<string, mixed>
 */
function agileBoard(string $name = 'XY Board', string $columnField = 'Stage', ?string $swimlaneField = 'Type', ?array $columns = null): array
{
    $columns ??= array_map(fn (State $state): string => $state->value, State::cases());

    return [
        'id' => 'agile-'.$name,
        'name' => $name,
        'projects' => [['id' => '0-9', 'shortName' => 'XY']],
        'columnSettings' => [
            'field' => ['id' => 'f-'.$columnField, 'name' => $columnField],
            'columns' => array_map(fn (string $value): array => ['presentation' => $value, 'fieldValues' => [['name' => $value]]], $columns),
        ],
        'swimlaneSettings' => $swimlaneField === null ? null : [
            '$type' => 'AttributeBasedSwimlaneSettings',
            'enabled' => true,
            'field' => ['id' => 'f-'.$swimlaneField, 'name' => $swimlaneField, 'customField' => ['id' => 'f-'.$swimlaneField, 'name' => $swimlaneField]],
        ],
    ];
}

/**
 * A stateful fake of the YouTrack REST API under https://yt.example.com/api: a project XY (id 0-9) with the given
 * custom fields, bundles, global fields, tags, saved searches, issues and agile boards (by default a board set
 * up for the cycle). Issues are a list, or a closure from the search query to a list. Writes answer with new
 * entities.
 *
 * @param  array<string, mixed>  $state
 */
function fakeYouTrackRestApi(array $state = []): void
{
    $state = [
        'project' => ['id' => '0-9', 'shortName' => 'XY', 'name' => 'Example'],
        'projectFields' => [],
        'bundles' => ['state' => [], 'enum' => []],
        'fields' => [],
        'tags' => [],
        'queries' => [],
        'issues' => [],
        'agiles' => [agileBoard()],
        ...$state,
    ];
    $sequence = 100;

    Http::fake(['yt.example.com/api/*' => function (Request $request) use (&$state, &$sequence) {
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
            $get && $path === 'issues' => Http::response($state['issues'] instanceof Closure ? ($state['issues'])((string) $request['query']) : $state['issues']),
            $get && $path === 'agiles' => $page($state['agiles']),
            $get && $path === 'users/me' => Http::response(['id' => '1-1', 'login' => 'agent', 'fullName' => 'Agent Smith']),
            $request->method() === 'DELETE' => Http::response(''),
            default => Http::response([
                'id' => 'new-'.(++$sequence),
                'name' => $body['name'] ?? null,
                'values' => array_map(fn (array $value): array => [...$value, 'id' => 'val-'.$value['name']], $body['values'] ?? []),
            ]),
        };
    }]);
}

/**
 * The write requests sent to the YouTrack REST API, as "METHOD path {json body}".
 *
 * @return list<string>
 */
function restWrites(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), '/api/'))
        ->map(fn (array $pair): string => $pair[0]->method().' '.preg_replace('#^https://yt\.example\.com/api/#', '', strtok($pair[0]->url(), '?') ?: '').' '.json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
        ->values()
        ->all();
}

/**
 * A host project with the agentio skills, a fake `artisan` (agentio:yt answers from the given ideas, every call
 * logged in artisan-calls.log) and a fake Claude Code binary, configured for the loop of the package.
 *
 * @param  list<string>  $ideas
 */
function projectForLoop(array $ideas = []): string
{
    $project = hostProject();
    mkdir($project.'/.claude/skills/agentio-work-epic', 0777, true);
    touch($project.'/.claude/skills/agentio-work-epic/SKILL.md');
    mkdir($project.'/bin');
    writeFakeArtisan($project, $ideas);
    file_put_contents($project.'/bin/claude', "#!/usr/bin/env bash\nsleep 1\nls \"\$AGENT_LOG_DIR\" > \"\$AGENT_LOG_DIR/seen-by-claude.txt\"\necho \"claude \$*\"\n");
    chmod($project.'/bin/claude', 0755);

    config([
        'agentio.youtrack.url' => 'https://yt.example.com',
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
        'agentio.base_branch' => 'develop',
        'agentio.max_parallel' => 3,
        'agentio.max_parallel_tasks' => 4,
        'agentio.interval' => 60,
        'agentio.worktrees_path' => $project.'/worktrees',
        'agentio.claude_binary' => $project.'/bin/claude',
        'agentio.logs_path' => $project.'/storage/logs/agents',
    ]);
    app()->forgetInstance(LoopState::class);

    return $project;
}

/**
 * A fake `artisan` of the host project: agentio:yt answers the ideas and empty lists, state answers
 * fixed values, agentio:pr prints the URL of a pull request; every call is appended to artisan-calls.log.
 *
 * @param  list<string>  $ideas
 */
function writeFakeArtisan(string $project, array $ideas = []): void
{
    $ideasJson = var_export((string) json_encode(array_map(fn (string $id): array => ['id' => $id, 'summary' => 'Idea'], $ideas)), true);
    $ideasText = var_export(implode('', array_map(fn (string $id): string => $id." Idea\n", $ideas)), true);

    file_put_contents($project.'/artisan', <<<PHP
        <?php
        file_put_contents(__DIR__.'/artisan-calls.log', implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        \$json = in_array('--json', \$argv, true);

        if ((\$argv[1] ?? '') === 'agentio:yt') {
            echo match (\$argv[2] ?? '') {
                'ideas' => \$json ? {$ideasJson} : {$ideasText},
                'state' => "Review\\n",
                'blocked' => "Blocked (needs a human):\\nReady but waiting for dependencies:\\n",
                default => \$json ? '[]' : '',
            };
            exit(0);
        }

        if ((\$argv[1] ?? '') === 'agentio:pr') {
            echo "  INFO  pull request #3 into develop opened.\n\nhttps://github.com/acme/app/pull/3\n";
            exit(0);
        }

        if ((\$argv[1] ?? '') === 'key:generate') {
            if (getenv('APP_KEY') !== false) {
                fwrite(STDERR, "Unable to set application key.\\n");
                exit(0);
            }
            file_put_contents('.env', preg_replace('/^APP_KEY=.*\$/m', 'APP_KEY=base64:generated', (string) file_get_contents('.env')));
        }
        PHP);
}

/**
 * A Telegram update with a message of the developer (chat 42), as getUpdates returns it.
 *
 * @param  array<string, mixed>  $message  Fields merged into the message (text, voice, reply_to_message, …)
 * @return array<string, mixed>
 */
function telegramUpdate(int $updateId, array $message = [], string $chatId = '42'): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId + 1000,
            'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'Dev', 'username' => 'dev'],
            'chat' => ['id' => (int) $chatId, 'type' => 'private'],
            'date' => 1791000000,
            'text' => 'Привет',
            ...$message,
        ],
    ];
}

/**
 * Fake the Telegram Bot API (any token, any API URL): method => its answer, as Http::fake() takes it (an array is
 * a JSON body with HTTP 200; Http::response() for another status; a closure from the request). "file" fakes the
 * downloads of the files.
 *
 * @param  array<string, mixed>  $methods
 */
function fakeTelegram(array $methods): void
{
    $stubs = [];

    foreach ($methods as $method => $answer) {
        $stubs[$method === 'file' ? '*/file/bot*' : '*/bot*/'.$method] = $answer;
    }

    Http::fake($stubs);
}

/**
 * A host project with the bot set up: token, the developer's chat 42 and the logs in the project.
 */
function projectWithBot(?string $chatId = '42'): string
{
    $project = hostProject();
    file_put_contents($project.'/.env', "APP_ENV=local\nAGENTIO_TELEGRAM_BOT_TOKEN=123456:TEST-token-0123456789abcdefghijklmnop\n".($chatId === null ? '' : "AGENTIO_TELEGRAM_CHAT_ID={$chatId}\n"));

    config([
        'agentio.telegram.token' => '123456:TEST-token-0123456789abcdefghijklmnop',
        'agentio.telegram.chat_id' => $chatId,
        'agentio.youtrack.url' => 'https://yt.example.com',
        'agentio.youtrack.token' => 'secret-token',
        'agentio.youtrack.project' => 'XY',
        'agentio.logs_path' => $project.'/storage/logs/agents',
    ]);
    app()->forgetInstance(LoopState::class);

    return $project;
}

/**
 * The bot of the current project paired with the chat 42, the Telegram queue faked, and the assistant (a faked
 * Claude Code session) answering every message with $decision.
 *
 * @param  array<string, mixed>  $decision
 */
function assistantDecides(array $decision): void
{
    Queue::fake();
    config(['agentio.telegram.token' => '123456:TEST-token-0123456789abcdefghijklmnop', 'agentio.telegram.chat_id' => '42']);
    Process::fake(['*--output-format*' => Process::result((string) json_encode([
        'type' => 'result',
        'is_error' => false,
        'result' => json_encode($decision, JSON_UNESCAPED_UNICODE),
    ]))]);
}
