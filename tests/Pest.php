<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Tests\TestCase;
use Obrazmisli\Agentio\YouTrack\Issue;

uses(TestCase::class)
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
 * A fresh temporary directory, removed after the test.
 */
function temporaryDirectory(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-'.Str::random(12);
    mkdir($directory, 0777, true);

    test()->beforeApplicationDestroyed(function () use ($directory): void {
        foreach (glob($directory.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    });

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
 * A stateful fake of the YouTrack REST API under https://yt.example.com/api: a project XY (id 0-9) with the given
 * custom fields, bundles, global fields, tags, saved searches and issues. Writes answer with new entities.
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
            $get && $path === 'issues' => Http::response($state['issues']),
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
        'agentio.merge_policy' => null,
        'agentio.max_parallel' => 3,
        'agentio.max_parallel_tasks' => 4,
        'agentio.interval' => 60,
        'agentio.worktrees_path' => $project.'/worktrees',
        'agentio.claude_binary' => $project.'/bin/claude',
        'agentio.logs_path' => $project.'/storage/logs/agents',
        'agentio.tests.command' => 'vendor/bin/pest --compact',
    ]);
    app()->forgetInstance(LoopState::class);

    return $project;
}

/**
 * A fake `artisan` of the host project: agentio:yt answers the ideas and empty lists, slug and state answer
 * fixed values; every call is appended to artisan-calls.log.
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
                'slug' => "profile-page\\n",
                'state' => "Review\\n",
                'blocked' => "Blocked (needs a human):\\nReady but waiting for dependencies:\\n",
                default => \$json ? '[]' : '',
            };
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
