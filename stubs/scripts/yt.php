<?php

declare(strict_types=1);

/*
 * Deterministic YouTrack helper for the autonomous development loop.
 *
 * It implements the rules from .claude/skills/youtrack-workflow (readiness, claiming,
 * graph validation, State -> Stage synchronisation) so that the dispatcher script and the
 * agents compute them the same way. Installed by obrazmisli/agentio (php artisan agentio:install).
 *
 * Settings come from the environment, falling back to the project's .env file:
 *   YOUTRACK_URL, YOUTRACK_TOKEN  (required) the YouTrack instance and a permanent token;
 *   AGENTIO_PROJECT               the project short name (default: {{project}}).
 *
 * Run `php scripts/yt.php help` for the list of commands.
 */

/**
 * A setting from the environment or, when it is not set there, from the project's .env file.
 */
function setting(string $name): string
{
    static $dotenv = null;

    $value = getenv($name);

    if (is_string($value) && $value !== '') {
        return $value;
    }

    if ($dotenv === null) {
        $dotenv = [];
        $file = dirname(__DIR__).'/.env';

        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $raw = trim($match[2]);
            $dotenv[$match[1]] = preg_match('/^(["\'])(.*)\1$/', $raw, $quoted) === 1
                ? $quoted[2]
                : trim((string) preg_replace('/\s+#.*$/', '', $raw));
        }
    }

    return $dotenv[$name] ?? '';
}

define('PROJECT', setting('AGENTIO_PROJECT') ?: '{{project}}');
const TAG_CLAIMED = 'agent-claimed';
const TAG_IDEA = 'idea';
const STATE_DONE = 'Done';
const STATE_REVIEW = 'Review';
const STATE_READY = 'Ready';
const STATE_BLOCKED = 'Blocked';
const STATE_IN_PROGRESS = 'In Progress';
const STATES = ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done'];

/**
 * The Stage field feeds the columns of the Kanban board and is derived from State. Nobody sets it by hand.
 */
const STAGE_FIELD = 'Stage';
const STAGE_BY_STATE = [
    'Backlog' => 'Backlog',
    'Analysis' => 'Backlog',
    'Ready' => 'Backlog',
    'Blocked' => 'Backlog',
    'In Progress' => 'Develop',
    'Review' => 'Review',
    'Done' => 'Done',
];
const RELATION_DEPENDS_ON = 'depends on';
const RELATION_REQUIRED_FOR = 'is required for';
const RELATION_SUBTASK_OF = 'subtask of';
const RELATION_PARENT_FOR = 'parent for';
const ISSUE_FIELDS = 'idReadable,summary,resolved,updated,customFields(name,value(name,login)),tags(id,name),'
    .'links(direction,linkType(name,sourceToTarget,targetToSource),issues(idReadable))';

/**
 * @return array<string, mixed>|list<mixed>
 */
function api(string $method, string $path, array $query = [], ?array $body = null): array
{
    $baseUrl = rtrim(setting('YOUTRACK_URL'), '/');
    $token = setting('YOUTRACK_TOKEN');

    if ($baseUrl === '' || $token === '') {
        fail('YOUTRACK_URL and YOUTRACK_TOKEN are required (environment variables or the project .env file).');
    }

    $url = $baseUrl.'/api/'.ltrim($path, '/').($query === [] ? '' : '?'.http_build_query($query));

    for ($attempt = 1; ; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (($response === false || $status >= 500 || $status === 429) && $attempt < 4) {
            sleep($attempt * 2);

            continue;
        }

        if ($response === false || $status >= 400) {
            throw new YouTrackException(sprintf('YouTrack %s %s failed with HTTP %d: %s', $method, $path, $status, is_string($response) ? $response : curl_error($curl)));
        }

        if (! is_string($response) || $response === '') {
            return [];
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

final class YouTrackException extends RuntimeException {}

set_exception_handler(fn (Throwable $exception) => fail($exception->getMessage()));

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

/**
 * @return list<array<string, mixed>>
 */
function searchIssues(string $query): array
{
    $issues = [];

    for ($skip = 0; ; $skip += 200) {
        $page = api('GET', 'issues', ['query' => $query, 'fields' => ISSUE_FIELDS, '$top' => 200, '$skip' => $skip]);
        array_push($issues, ...$page);

        if (count($page) < 200) {
            return array_map(normalizeIssue(...), $issues);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function getIssue(string $id): array
{
    return normalizeIssue(api('GET', 'issues/'.$id, ['fields' => ISSUE_FIELDS]));
}

/**
 * @param  array<string, mixed>  $raw
 * @return array{id: string, summary: string, state: ?string, type: ?string, stage: ?string, tags: list<string>, tagIds: array<string, string>, relations: array<string, list<string>>, updated: int}
 */
function normalizeIssue(array $raw): array
{
    $fields = [];

    foreach ($raw['customFields'] ?? [] as $field) {
        $fields[$field['name']] = $field['value']['name'] ?? $field['value']['login'] ?? null;
    }

    $relations = [];

    foreach ($raw['links'] ?? [] as $link) {
        $type = $link['linkType'];
        $relation = match ($link['direction']) {
            'INWARD' => $type['targetToSource'],
            default => $type['sourceToTarget'],
        };

        foreach ($link['issues'] as $linked) {
            $relations[$relation][] = $linked['idReadable'];
        }
    }

    $tagIds = [];

    foreach ($raw['tags'] ?? [] as $tag) {
        $tagIds[$tag['name']] = $tag['id'];
    }

    return [
        'id' => $raw['idReadable'],
        'summary' => $raw['summary'],
        'state' => $fields['State'] ?? null,
        'type' => $fields['Type'] ?? null,
        'stage' => $fields[STAGE_FIELD] ?? null,
        'tags' => array_keys($tagIds),
        'tagIds' => $tagIds,
        'relations' => $relations,
        'updated' => (int) ($raw['updated'] ?? 0),
    ];
}

/**
 * @param  array<string, mixed>  $issue
 * @return list<string>
 */
function related(array $issue, string $relation): array
{
    return $issue['relations'][$relation] ?? [];
}

final class Graph
{
    /** @var array<string, array<string, mixed>> */
    public array $issues = [];

    public static function project(): self
    {
        $graph = new self;

        foreach (searchIssues('project: '.PROJECT) as $issue) {
            $graph->issues[$issue['id']] = $issue;
        }

        return $graph;
    }

    public function get(string $id): array
    {
        return $this->issues[$id] ?? $this->issues[$id] = getIssue($id);
    }

    public function parentOf(string $id): ?string
    {
        return related($this->get($id), RELATION_SUBTASK_OF)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ancestors(string $id): array
    {
        $chain = [];

        for ($parent = $this->parentOf($id); $parent !== null && ! in_array($parent, $chain, true); $parent = $this->parentOf($parent)) {
            $chain[] = $parent;
        }

        return $chain;
    }

    public function epicOf(string $id): ?string
    {
        foreach ([$id, ...$this->ancestors($id)] as $candidate) {
            if ($this->get($candidate)['type'] === 'Epic') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function descendants(string $id): array
    {
        $result = [];
        $queue = related($this->get($id), RELATION_PARENT_FOR);

        while ($queue !== []) {
            $child = array_shift($queue);

            if (in_array($child, $result, true)) {
                continue;
            }

            $result[] = $child;
            array_push($queue, ...related($this->get($child), RELATION_PARENT_FOR));
        }

        return $result;
    }

    /**
     * Dependencies that still block the issue: its own and the ones inherited from its ancestors.
     * A dependency in Review counts as satisfied when it lives in the same epic (its code is on the epic branch).
     *
     * @return list<string>
     */
    public function unmetDependencies(string $id): array
    {
        $epic = $this->epicOf($id);
        $unmet = [];

        foreach ([$id, ...$this->ancestors($id)] as $holder) {
            foreach (related($this->get($holder), RELATION_DEPENDS_ON) as $dependency) {
                $state = $this->get($dependency)['state'];
                $sameEpic = $epic !== null && $this->epicOf($dependency) === $epic;

                if ($state === STATE_DONE || ($sameEpic && $state === STATE_REVIEW)) {
                    continue;
                }

                $unmet[] = $dependency;
            }
        }

        return array_values(array_unique($unmet));
    }

    public function isTaskReady(string $id): bool
    {
        $task = $this->get($id);

        return $task['type'] === 'Task'
            && $task['state'] === STATE_READY
            && ! in_array(TAG_CLAIMED, $task['tags'], true)
            && $this->unmetDependencies($id) === [];
    }

    /**
     * @return list<string>
     */
    public function readyTasks(string $epic): array
    {
        return array_values(array_filter($this->descendants($epic), $this->isTaskReady(...)));
    }

    public function isEpicReady(string $id): bool
    {
        $epic = $this->get($id);

        return $epic['type'] === 'Epic'
            && $epic['state'] === STATE_READY
            && ! in_array(TAG_CLAIMED, $epic['tags'], true)
            && $this->unmetDependencies($id) === []
            && $this->readyTasks($id) !== [];
    }

    /**
     * @return list<list<string>>
     */
    public function dependencyCycles(): array
    {
        $cycles = [];
        $visiting = [];
        $done = [];

        $visit = function (string $id, array $path) use (&$visit, &$cycles, &$visiting, &$done): void {
            if (isset($done[$id])) {
                return;
            }

            if (isset($visiting[$id])) {
                $cycles[] = [...array_slice($path, (int) array_search($id, $path, true)), $id];

                return;
            }

            $visiting[$id] = true;

            foreach (related($this->get($id), RELATION_DEPENDS_ON) as $dependency) {
                $visit($dependency, [...$path, $id]);
            }

            unset($visiting[$id]);
            $done[$id] = true;
        };

        foreach (array_keys($this->issues) as $id) {
            $visit($id, []);
        }

        return $cycles;
    }
}

/**
 * @return list<array{author: string, created: int, text: string}>
 */
function comments(string $id): array
{
    $comments = api('GET', 'issues/'.$id.'/comments', ['fields' => 'text,created,author(login)', '$top' => 1000]);

    return array_map(fn (array $comment): array => [
        'author' => $comment['author']['login'] ?? '',
        'created' => (int) $comment['created'],
        'text' => (string) $comment['text'],
    ], $comments);
}

/**
 * @return list<array{author: string, created: int, text: string}>
 */
function agentComments(string $id): array
{
    return array_values(array_filter(comments($id), fn (array $comment): bool => str_starts_with(ltrim($comment['text']), '[AGENT:')));
}

function lastAgentComment(string $id, string $kind): ?string
{
    $matching = array_filter(agentComments($id), fn (array $comment): bool => str_starts_with(ltrim($comment['text']), '[AGENT:'.$kind.']'));

    return $matching === [] ? null : end($matching)['text'];
}

/**
 * The owner of the active claim: the earliest [AGENT:START] posted after the last [AGENT:DONE] / [AGENT:BLOCKED] / [AGENT:RELEASE].
 */
function claimOwner(string $id): ?string
{
    $owner = null;

    foreach (agentComments($id) as $comment) {
        $text = ltrim($comment['text']);

        if (preg_match('/^\[AGENT:(DONE|BLOCKED|RELEASE)\]/', $text) === 1) {
            $owner = null;
        } elseif ($owner === null && str_starts_with($text, '[AGENT:START]') && preg_match('/^owner:\s*`?([^`\s]+)`?/mi', $text, $match) === 1) {
            $owner = $match[1];
        }
    }

    return $owner;
}

function tagId(string $name): string
{
    foreach (api('GET', 'tags', ['fields' => 'id,name', 'query' => $name, '$top' => 100]) as $tag) {
        if ($tag['name'] === $name) {
            return $tag['id'];
        }
    }

    fail("Tag {$name} does not exist in YouTrack.");
}

/**
 * Whether the project has the Stage field. Checked once per run: the project settings first,
 * then (when the token may not read them) the fields of any project issue.
 */
function projectHasStage(): bool
{
    static $hasStage = null;

    if ($hasStage !== null) {
        return $hasStage;
    }

    try {
        $fields = api('GET', 'admin/projects/'.PROJECT.'/customFields', ['fields' => 'field(name)', '$top' => 200]);
        $names = array_map(fn (array $field): string => (string) ($field['field']['name'] ?? ''), $fields);
    } catch (YouTrackException) {
        $issues = api('GET', 'issues', ['query' => 'project: '.PROJECT, 'fields' => 'customFields(name)', '$top' => 1]);
        $names = array_map(fn (array $field): string => (string) $field['name'], $issues[0]['customFields'] ?? []);
    }

    return $hasStage = in_array(STAGE_FIELD, $names, true);
}

function validState(string $state): string
{
    foreach (STATES as $known) {
        if (mb_strtolower($known) === mb_strtolower(trim($state))) {
            return $known;
        }
    }

    fail("Unknown State '{$state}'. Allowed: ".implode(', ', STATES).'.');
}

function stageFor(?string $state): ?string
{
    return $state === null ? null : (STAGE_BY_STATE[$state] ?? null);
}

/**
 * @return array{name: string, $type: string, value: array{name: string}}
 */
function stateField(string $name, string $value): array
{
    return ['name' => $name, '$type' => 'StateIssueCustomField', 'value' => ['name' => $value]];
}

/**
 * Sets State and, when the project has it, the derived Stage in one request.
 */
function setState(string $id, string $state): ?string
{
    $state = validState($state);
    $stage = projectHasStage() ? stageFor($state) : null;
    $fields = [stateField('State', $state)];

    if ($stage !== null) {
        $fields[] = stateField(STAGE_FIELD, $stage);
    }

    api('POST', 'issues/'.$id, ['fields' => 'idReadable'], ['customFields' => $fields]);

    return $stage;
}

function setStage(string $id, string $stage): void
{
    api('POST', 'issues/'.$id, ['fields' => 'idReadable'], ['customFields' => [stateField(STAGE_FIELD, $stage)]]);
}

function addComment(string $id, string $text): void
{
    api('POST', 'issues/'.$id.'/comments', ['fields' => 'id'], ['text' => $text]);
}

function addTag(string $id, string $tag): void
{
    api('POST', 'issues/'.$id.'/tags', ['fields' => 'id'], ['id' => tagId($tag)]);
}

function removeTag(string $id, string $tag): void
{
    $issue = getIssue($id);

    if (isset($issue['tagIds'][$tag])) {
        api('DELETE', 'issues/'.$id.'/tags/'.$issue['tagIds'][$tag]);
    }
}

function defaultOwner(string $worktree): string
{
    return gethostname().':'.$worktree;
}

function gitOutput(string $arguments): string
{
    $output = trim((string) shell_exec('git '.$arguments.' 2>/dev/null'));

    return $output !== '' ? $output : fail("Could not determine `git {$arguments}`; pass the value explicitly.");
}

/**
 * @param  list<string>  $arguments
 * @return array{0: list<string>, 1: array<string, string|true>}
 */
function parseArguments(array $arguments): array
{
    $positional = [];
    $options = [];

    foreach ($arguments as $index => $argument) {
        if (str_starts_with($argument, '--')) {
            [$name, $value] = array_pad(explode('=', mb_substr($argument, 2), 2), 2, true);
            $options[$name] = $value;
        } else {
            $positional[] = $argument;
        }
    }

    return [$positional, $options];
}

function output(mixed $data, bool $json, callable $render): void
{
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }

    $render($data);
}

function slug(string $summary): string
{
    $text = preg_replace('/^\s*\[[A-Z]+\]\s*/', '', $summary) ?? $summary;
    $latin = function_exists('transliterator_transliterate')
        ? transliterator_transliterate('Any-Latin; Latin-ASCII', $text)
        : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = mb_strtolower(str_replace(["'", '"', 'ʹ', 'ʺ'], '', (string) ($latin ?: $text)));
    $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');

    return mb_substr($text, 0, 40) ?: 'epic';
}

function line(array $issue, string $suffix = ''): string
{
    return sprintf('%-7s %-12s %-6s %s%s', $issue['id'], $issue['state'] ?? '-', $issue['type'] ?? '-', $issue['summary'], $suffix);
}

[$positional, $options] = parseArguments(array_slice($argv, 1));
$command = $positional[0] ?? 'help';
$json = isset($options['json']);

switch ($command) {
    case 'ideas':
        $ideas = [];

        foreach (['tag: '.TAG_IDEA, 'Type: Idea'] as $filter) {
            // `State: Backlog` also matches issues whose Stage is Backlog (same value name), so State is re-checked here.
            foreach (searchIssues('project: '.PROJECT.' '.$filter.' State: Backlog') as $issue) {
                if ($issue['state'] === 'Backlog' && ! in_array(TAG_CLAIMED, $issue['tags'], true)) {
                    $ideas[$issue['id']] = ['id' => $issue['id'], 'summary' => $issue['summary']];
                }
            }
        }

        output(array_values($ideas), $json, function (array $ideas): void {
            foreach ($ideas as $idea) {
                echo $idea['id'].' '.$idea['summary'].PHP_EOL;
            }
        });
        break;

    case 'ready-epics':
        $graph = Graph::project();
        $epics = [];

        foreach ($graph->issues as $id => $issue) {
            if ($issue['type'] === 'Epic' && $graph->isEpicReady($id)) {
                $epics[] = ['id' => $id, 'summary' => $issue['summary'], 'slug' => slug($issue['summary']), 'readyTasks' => $graph->readyTasks($id)];
            }
        }

        output($epics, $json, function (array $epics): void {
            foreach ($epics as $epic) {
                echo $epic['id'].' '.$epic['slug'].' ready-tasks='.implode(',', $epic['readyTasks']).' '.$epic['summary'].PHP_EOL;
            }
        });
        break;

    case 'claimed-epics':
        $epics = [];

        foreach (searchIssues('project: '.PROJECT.' Type: Epic tag: '.TAG_CLAIMED) as $issue) {
            $epics[] = ['id' => $issue['id'], 'state' => $issue['state'], 'summary' => $issue['summary'], 'slug' => slug($issue['summary']), 'owner' => claimOwner($issue['id'])];
        }

        output($epics, $json, function (array $epics): void {
            foreach ($epics as $epic) {
                echo $epic['id'].' '.$epic['state'].' owner='.($epic['owner'] ?? '-').' '.$epic['summary'].PHP_EOL;
            }
        });
        break;

    case 'tree':
        $epic = $positional[1] ?? fail('Usage: tree <EPIC-ID>');
        $graph = Graph::project();
        $nodes = [];

        foreach ([$epic, ...$graph->descendants($epic)] as $id) {
            $issue = $graph->get($id);
            $nodes[] = [
                'id' => $id,
                'type' => $issue['type'],
                'state' => $issue['state'],
                'summary' => $issue['summary'],
                'parent' => $graph->parentOf($id),
                'dependsOn' => related($issue, RELATION_DEPENDS_ON),
                'unmetDependencies' => $graph->unmetDependencies($id),
                'claimed' => in_array(TAG_CLAIMED, $issue['tags'], true),
                'ready' => $issue['type'] === 'Task' ? $graph->isTaskReady($id) : null,
            ];
        }

        output($nodes, $json, function (array $nodes): void {
            foreach ($nodes as $node) {
                $indent = match ($node['type']) {
                    'Story' => '  ',
                    'Task' => '    ',
                    default => '',
                };
                $flags = ($node['ready'] ? ' [READY]' : '').($node['claimed'] ? ' [CLAIMED]' : '').($node['unmetDependencies'] !== [] ? ' waits:'.implode(',', $node['unmetDependencies']) : '');
                echo $indent.line($node, $flags).PHP_EOL;
            }
        });
        break;

    case 'ready-tasks':
        $epic = $positional[1] ?? fail('Usage: ready-tasks <EPIC-ID>');
        $graph = Graph::project();
        $tasks = array_map(fn (string $id): array => ['id' => $id, 'summary' => $graph->get($id)['summary'], 'story' => $graph->parentOf($id)], $graph->readyTasks($epic));

        output($tasks, $json, function (array $tasks): void {
            foreach ($tasks as $task) {
                echo $task['id'].' (story '.($task['story'] ?? '-').') '.$task['summary'].PHP_EOL;
            }
        });
        break;

    case 'blocked':
        $graph = Graph::project();
        $blocked = [];
        $waiting = [];

        foreach ($graph->issues as $id => $issue) {
            if ($issue['state'] === STATE_BLOCKED) {
                $blocked[] = ['id' => $id, 'type' => $issue['type'], 'summary' => $issue['summary'], 'reason' => lastAgentComment($id, 'BLOCKED') ?? '(no [AGENT:BLOCKED] comment)'];
            } elseif ($issue['state'] === STATE_READY && in_array($issue['type'], ['Task', 'Epic'], true)) {
                $unmet = $graph->unmetDependencies($id);

                if ($unmet !== []) {
                    $waiting[] = ['id' => $id, 'type' => $issue['type'], 'summary' => $issue['summary'], 'waitsFor' => array_map(fn (string $dependency): string => $dependency.' ('.($graph->get($dependency)['state'] ?? '?').')', $unmet)];
                }
            }
        }

        output(['blocked' => $blocked, 'waitingForDependencies' => $waiting], $json, function (array $data): void {
            echo 'Blocked (needs a human):'.PHP_EOL;

            foreach ($data['blocked'] as $item) {
                echo '  '.$item['id'].' '.$item['summary'].PHP_EOL.'    '.str_replace("\n", "\n    ", trim($item['reason'])).PHP_EOL;
            }

            echo 'Ready but waiting for dependencies:'.PHP_EOL;

            foreach ($data['waitingForDependencies'] as $item) {
                echo '  '.$item['id'].' '.$item['summary'].' <- '.implode(', ', $item['waitsFor']).PHP_EOL;
            }
        });
        break;

    case 'status':
        $graph = Graph::project();
        $counts = [];

        foreach ($graph->issues as $issue) {
            $type = $issue['type'] ?? '-';
            $state = $issue['state'] ?? '-';
            $counts[$type][$state] = ($counts[$type][$state] ?? 0) + 1;
        }

        $pick = fn (callable $filter): array => array_values(array_map(
            fn (array $issue): array => ['id' => $issue['id'], 'type' => $issue['type'], 'state' => $issue['state'], 'summary' => $issue['summary']],
            array_filter($graph->issues, $filter),
        ));

        $status = [
            'counts' => $counts,
            'inProgress' => $pick(fn (array $issue): bool => $issue['state'] === STATE_IN_PROGRESS || in_array(TAG_CLAIMED, $issue['tags'], true)),
            'blocked' => $pick(fn (array $issue): bool => $issue['state'] === STATE_BLOCKED),
            'awaitingHuman' => $pick(fn (array $issue): bool => $issue['state'] === STATE_REVIEW && in_array($issue['type'], ['Epic', 'Idea'], true)),
        ];

        output($status, $json, function (array $status): void {
            foreach ($status['counts'] as $type => $states) {
                echo str_pad((string) $type, 6).' '.implode('  ', array_map(fn (string $state, int $count): string => $state.'='.$count, array_keys($states), $states)).PHP_EOL;
            }

            foreach (['inProgress' => 'In progress / claimed', 'blocked' => 'Blocked', 'awaitingHuman' => 'Awaiting human (Review)'] as $key => $title) {
                echo PHP_EOL.$title.':'.PHP_EOL;

                foreach ($status[$key] as $issue) {
                    echo '  '.line($issue).PHP_EOL;
                }
            }
        });
        break;

    case 'validate':
        $root = $positional[1] ?? fail('Usage: validate <EPIC-ID|IDEA-ID>');
        $graph = Graph::project();
        $epics = $graph->get($root)['type'] === 'Epic' ? [$root] : array_values(array_filter(
            array_keys($graph->issues),
            fn (string $id): bool => $graph->issues[$id]['type'] === 'Epic' && in_array($root, related($graph->issues[$id], 'relates to'), true),
        ));
        $problems = [];
        $prefixes = ['Epic' => '[EPIC]', 'Story' => '[STORY]', 'Task' => '[TASK]'];

        if ($epics === []) {
            $problems[] = "{$root}: no epics found (an idea must be linked to its epics with 'relates to').";
        }

        foreach ($epics as $epic) {
            foreach ([$epic, ...$graph->descendants($epic)] as $id) {
                $issue = $graph->get($id);
                $prefix = $prefixes[$issue['type']] ?? null;

                if ($prefix === null || ! str_starts_with($issue['summary'], $prefix)) {
                    $problems[] = "{$id}: Type '{$issue['type']}' does not match the summary prefix.";
                }

                $parent = $graph->parentOf($id);
                $expectedParent = ['Story' => 'Epic', 'Task' => 'Story'][$issue['type']] ?? null;

                if ($expectedParent !== null && ($parent === null || $graph->get($parent)['type'] !== $expectedParent)) {
                    $problems[] = "{$id}: a {$issue['type']} must be a subtask of a {$expectedParent}.";
                }

                if ($issue['type'] === 'Story' && related($issue, RELATION_PARENT_FOR) === []) {
                    $problems[] = "{$id}: story has no tasks.";
                }
            }

            if ($graph->readyTasks($epic) === [] && $graph->get($epic)['state'] === STATE_READY) {
                $problems[] = "{$epic}: epic is Ready but its first wave of ready tasks is empty.";
            }
        }

        foreach ($graph->dependencyCycles() as $cycle) {
            $problems[] = 'Dependency cycle: '.implode(' -> ', $cycle);
        }

        output(['ok' => $problems === [], 'epics' => $epics, 'problems' => $problems], $json, function (array $result): void {
            echo ($result['ok'] ? 'OK' : 'PROBLEMS').' epics='.implode(',', $result['epics']).PHP_EOL;

            foreach ($result['problems'] as $problem) {
                echo '  - '.$problem.PHP_EOL;
            }
        });
        exit($problems === [] ? 0 : 2);

    case 'context':
        $id = $positional[1] ?? fail('Usage: context <ISSUE-ID>');
        $graph = new Graph;
        $raw = api('GET', 'issues/'.$id, ['fields' => 'idReadable,summary,description,'.mb_substr(ISSUE_FIELDS, mb_strlen('idReadable,summary,'))]);
        $issue = normalizeIssue($raw);
        $chain = array_map(function (string $ancestor): array {
            $data = api('GET', 'issues/'.$ancestor, ['fields' => 'idReadable,summary,description']);

            return ['id' => $ancestor, 'summary' => $data['summary'], 'description' => $data['description'] ?? ''];
        }, $graph->ancestors($id));

        $context = [
            'issue' => [...$issue, 'description' => $raw['description'] ?? ''],
            'ancestors' => $chain,
            'children' => array_map(fn (string $child): array => ['id' => $child, 'state' => $graph->get($child)['state'], 'summary' => $graph->get($child)['summary']], related($issue, RELATION_PARENT_FOR)),
            'unmetDependencies' => $graph->unmetDependencies($id),
            'claimOwner' => claimOwner($id),
            'agentComments' => array_merge(...array_map(
                fn (string $holder): array => array_map(fn (array $comment): array => ['issue' => $holder, ...$comment], agentComments($holder)),
                [$id, ...$graph->ancestors($id)],
            )),
        ];

        output($context, $json, function (array $context): void {
            $issue = $context['issue'];
            echo '# '.line($issue).PHP_EOL.PHP_EOL.$issue['description'].PHP_EOL;
            echo PHP_EOL.'Relations: '.json_encode($issue['relations'], JSON_UNESCAPED_UNICODE).PHP_EOL;
            echo 'Tags: '.implode(', ', $issue['tags']).' | claim owner: '.($context['claimOwner'] ?? '-').' | unmet deps: '.implode(', ', $context['unmetDependencies']).PHP_EOL;

            foreach ($context['ancestors'] as $ancestor) {
                echo PHP_EOL.'## Parent '.$ancestor['id'].' '.$ancestor['summary'].PHP_EOL.PHP_EOL.$ancestor['description'].PHP_EOL;
            }

            if ($context['children'] !== []) {
                echo PHP_EOL.'## Children'.PHP_EOL;

                foreach ($context['children'] as $child) {
                    echo '  '.$child['id'].' '.$child['state'].' '.$child['summary'].PHP_EOL;
                }
            }

            echo PHP_EOL.'## [AGENT:*] comments (issue and parents, oldest first)'.PHP_EOL;

            foreach ($context['agentComments'] as $comment) {
                echo PHP_EOL.'--- '.$comment['issue'].' @ '.gmdate('Y-m-d H:i', intdiv($comment['created'], 1000)).' UTC'.PHP_EOL.trim($comment['text']).PHP_EOL;
            }
        });
        break;

    case 'claim':
        $id = $positional[1] ?? fail('Usage: claim <ID> [--as=<suffix>] [--plan=<text>] [--branch=<branch>] [--worktree=<path>] [--owner=<owner>]');
        $worktree = is_string($options['worktree'] ?? null) ? $options['worktree'] : gitOutput('rev-parse --show-toplevel');
        $branch = is_string($options['branch'] ?? null) ? $options['branch'] : gitOutput('branch --show-current');
        $owner = is_string($options['owner'] ?? null) ? $options['owner'] : defaultOwner($worktree).(is_string($options['as'] ?? null) ? '#'.$options['as'] : '');
        $current = claimOwner($id);

        if ($current !== null && $current !== $owner) {
            output(['claimed' => false, 'owner' => $current], $json, fn (array $result) => print "LOST: {$id} is claimed by {$result['owner']}".PHP_EOL);
            exit(3);
        }

        if ($current === null) {
            $plan = is_string($options['plan'] ?? null) ? $options['plan'] : 'Plan follows in the next [AGENT:START] comment.';
            addComment($id, "[AGENT:START]\nowner: `{$owner}`\nbranch: `{$branch}`\nworktree: `{$worktree}`\n\n{$plan}");
        }

        addTag($id, TAG_CLAIMED);
        setState($id, STATE_IN_PROGRESS);
        $winner = claimOwner($id);

        if ($winner !== $owner) {
            output(['claimed' => false, 'owner' => $winner], $json, fn (array $result) => print "LOST: {$id} is claimed by {$result['owner']}".PHP_EOL);
            exit(3);
        }

        output(['claimed' => true, 'owner' => $owner, 'resumed' => $current !== null], $json, fn (array $result) => print ($result['resumed'] ? 'RESUMED' : 'CLAIMED')." {$id} as {$owner}".PHP_EOL);
        break;

    case 'release':
        $id = $positional[1] ?? fail('Usage: release <ID> --state=<State> [--comment=<text>]');
        $state = validState((string) ($options['state'] ?? fail('--state is required')));

        if (is_string($options['comment'] ?? null)) {
            addComment($id, $options['comment']);
        }

        setState($id, $state);
        removeTag($id, TAG_CLAIMED);
        echo "RELEASED {$id} -> {$state}".PHP_EOL;
        break;

    case 'set-state':
        $id = $positional[1] ?? fail('Usage: set-state <ID> <State> [--comment=<text>]');
        $state = validState($positional[2] ?? fail('Usage: set-state <ID> <State> [--comment=<text>]  (State: '.implode(', ', STATES).')'));

        if (is_string($options['comment'] ?? null)) {
            addComment($id, $options['comment']);
        }

        $stage = setState($id, $state);
        output(['id' => $id, 'state' => $state, 'stage' => $stage], $json, fn (array $result) => print "STATE {$id} -> {$state}".($stage === null ? '' : " (Stage {$stage})").PHP_EOL);
        break;

    case 'sync-stage':
        $dryRun = isset($options['dry-run']);

        if (! projectHasStage()) {
            output(['stageField' => false, 'dryRun' => $dryRun, 'checked' => 0, 'changes' => []], $json, fn () => print 'Project '.PROJECT.' has no '.STAGE_FIELD.' field: nothing to sync'.PHP_EOL);
            break;
        }

        $ids = array_slice($positional, 1);
        $issues = $ids === [] ? searchIssues('project: '.PROJECT) : array_map(getIssue(...), $ids);
        $changes = [];

        foreach ($issues as $issue) {
            $expected = stageFor($issue['state']);

            if ($expected === null || $issue['stage'] === $expected) {
                continue;
            }

            if (! $dryRun) {
                setStage($issue['id'], $expected);
            }

            $changes[] = ['id' => $issue['id'], 'state' => $issue['state'], 'from' => $issue['stage'], 'to' => $expected];
        }

        output(['stageField' => true, 'dryRun' => $dryRun, 'checked' => count($issues), 'changes' => $changes], $json, function (array $result): void {
            foreach ($result['changes'] as $change) {
                echo $change['id'].' State='.$change['state'].': Stage '.($change['from'] ?? '-').' -> '.$change['to'].PHP_EOL;
            }

            echo sprintf('%d issue(s) checked, %d %s', $result['checked'], count($result['changes']), $result['dryRun'] ? 'would be fixed (dry run)' : 'fixed').PHP_EOL;
        });
        break;

    case 'slug':
        echo slug(getIssue($positional[1] ?? fail('Usage: slug <ID>'))['summary']).PHP_EOL;
        break;

    default:
        echo <<<'HELP'
            Usage: php scripts/yt.php <command> [args] [--json]
            Env (or .env): YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_PROJECT (project short name)

              ideas                    Ideas waiting for planning (tag idea or Type Idea, State Backlog, not claimed)
              ready-epics              Epics ready to be worked on (full readiness rule)
              claimed-epics            Epics with the agent-claimed tag and their claim owners
              tree <EPIC>              Epic -> stories -> tasks with states, dependencies and readiness
              ready-tasks <EPIC>       Tasks of the epic that can be started now
              blocked                  Blocked issues with reasons and Ready issues waiting for dependencies
              status                   Project summary: counts, in progress, blocked, awaiting a human
              validate <EPIC|IDEA>     Structure checks: prefixes/types, parents, empty stories, first wave, cycles
              context <ID>             Issue, parents, children, unmet dependencies and all [AGENT:*] comments
              claim <ID> [--as=SUFFIX] [--plan=TEXT] [--branch=B] [--worktree=W] [--owner=O]
                                       Claim (or resume own claim): [AGENT:START], tag, In Progress (+Stage), re-read, verify.
                                       Defaults: current git branch and worktree, owner <host>:<worktree>[#SUFFIX]
              release <ID> --state=S [--comment=TEXT]
                                       Post an optional comment, set state (and Stage) and remove the agent-claimed tag
              set-state <ID> <State> [--comment=TEXT]
                                       Post an optional comment and set State together with the derived Stage.
                                       State: Backlog, Analysis, Ready, In Progress, Review, Blocked, Done
              sync-stage [<ID>...] [--dry-run]
                                       Fix Stage where it does not match State (all project issues by default).
                                       Stage = Backlog for Backlog/Analysis/Ready/Blocked, Develop for In Progress,
                                       Review for Review, Done for Done. Skipped when the project has no Stage field
              slug <ID>                Branch slug for an issue summary

            HELP;
}
