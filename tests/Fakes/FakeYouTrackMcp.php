<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Tests\Fakes;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful fake of the YouTrack MCP server at https://yt.example.com/mcp, with the paging limits and the query
 * language subset agentio uses: issues with Type, Stage, tags, parent and dependencies, comments and articles.
 * Tools not modelled here can be answered with on().
 */
final class FakeYouTrackMcp
{
    public const string URL = 'https://yt.example.com';

    /** @var array<string, array{id: string, summary: string, description: string, fields: array<string, string|null>, tags: list<string>, parent: string|null, dependsOn: list<string>, relatesTo: list<string>}> */
    public array $issues = [];

    /** @var array<string, list<array{author: string, text: string, createdAt: int}>> */
    public array $comments = [];

    /** @var array<string, array{id: string, summary: string, content: string, parent: string|null}> */
    public array $articles = [];

    /** @var list<array{tool: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, Closure(array<string, mixed>): mixed> */
    private array $handlers = [];

    private int $time = 1_791_000_000_000;

    public function __construct(public string $project = 'XY', public string $user = 'agent') {}

    /**
     * Fake the HTTP requests to the MCP endpoint with this server.
     */
    public function fake(): self
    {
        Http::fake([self::URL.'/mcp' => fn (Request $request): PromiseInterface => $this->respond($request)]);

        return $this;
    }

    /**
     * Answer one request to the MCP endpoint (for a test that fakes the HTTP layer itself).
     */
    public function handle(Request $request): PromiseInterface
    {
        return $this->respond($request);
    }

    /**
     * @param  array<string, string|null>  $fields
     * @param  list<string>  $tags
     * @param  list<string>  $dependsOn
     * @param  list<string>  $relatesTo
     */
    public function issue(string $id, string $type, string $state, ?string $parent = null, array $dependsOn = [], array $tags = [], ?string $summary = null, array $relatesTo = [], array $fields = []): self
    {
        $prefix = ['Idea' => '[IDEA]', 'Epic' => '[EPIC]', 'Story' => '[STORY]', 'Task' => '[TASK]'][$type] ?? '';

        $this->issues[$id] = [
            'id' => $id,
            'summary' => $summary ?? trim($prefix.' Summary of '.$id),
            'description' => 'Description of '.$id,
            'fields' => ['Type' => $type, 'Stage' => $state, ...$fields],
            'tags' => $tags,
            'parent' => $parent,
            'dependsOn' => $dependsOn,
            'relatesTo' => $relatesTo,
        ];

        return $this;
    }

    public function comment(string $id, string $text, string $author = 'agent'): self
    {
        $this->comments[$id][] = ['author' => $author, 'text' => $text, 'createdAt' => $this->time += 1000];

        return $this;
    }

    public function article(string $id, string $summary, ?string $parent = null, string $content = ''): self
    {
        $this->articles[$id] = ['id' => $id, 'summary' => $summary, 'content' => $content, 'parent' => $parent];

        return $this;
    }

    /**
     * Answer a tool with a closure that gets the arguments; a string is the text, an array becomes JSON,
     * FakeYouTrackMcp::error() an error.
     *
     * @param  Closure(array<string, mixed>): mixed  $handler
     */
    public function on(string $tool, Closure $handler): self
    {
        $this->handlers[$tool] = $handler;

        return $this;
    }

    /**
     * @return array{__error: string}
     */
    public static function error(string $message): array
    {
        return ['__error' => $message];
    }

    /**
     * The arguments of every call of the tool.
     *
     * @return list<array<string, mixed>>
     */
    public function callsOf(string $tool): array
    {
        return array_values(array_map(
            fn (array $call): array => $call['arguments'],
            array_filter($this->calls, fn (array $call): bool => $call['tool'] === $tool),
        ));
    }

    /**
     * The JSON-RPC message of a request.
     *
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $payload = json_decode($request->body(), true);

        return is_array($payload) ? $payload : [];
    }

    private function respond(Request $request): PromiseInterface
    {
        $payload = self::payload($request);
        $id = $payload['id'] ?? null;

        if ($request->header('Authorization') !== ['Bearer secret-token']) {
            return Http::response('Unauthorized', 401);
        }

        if ($id === null) {
            return Http::response('', 202);
        }

        if (($payload['method'] ?? null) === 'initialize') {
            return Http::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'YouTrack MCP', 'version' => 'fake'],
            ]], 200, ['Mcp-Session-Id' => 'session-1']);
        }

        $tool = (string) ($payload['params']['name'] ?? '');
        $arguments = (array) ($payload['params']['arguments'] ?? []);
        $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];
        $answer = isset($this->handlers[$tool]) ? ($this->handlers[$tool])($arguments) : $this->tool($tool, $arguments);
        $error = is_array($answer) && isset($answer['__error']);
        $text = match (true) {
            $error => 'Error: '.$answer['__error'],
            is_string($answer) => $answer,
            default => (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $error]]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $tool, array $arguments): mixed
    {
        $issueId = is_string($arguments['issueId'] ?? null) ? $arguments['issueId'] : '';
        $limit = (int) ($arguments['limit'] ?? 10);
        $offset = (int) ($arguments['offset'] ?? 0);

        return match ($tool) {
            'get_current_user' => ['login' => $this->user, 'name' => 'Agent Smith', 'timeZoneId' => 'UTC', 'email' => 'agent@example.com'],
            'get_project' => $arguments['projectKey'] === $this->project
                ? ['key' => $this->project, 'name' => 'Project '.$this->project, 'leader' => $this->user]
                : self::error('Project not found: '.(string) $arguments['projectKey']),
            'get_issue' => isset($this->issues[$issueId]) ? $this->issueAnswer($issueId) : self::error('Issue not found: '.$issueId),
            'search_issues' => $limit > 20 ? self::error('Argument limit exceeds maximum 20.0') : $this->page('issuesPage', array_map(
                fn (string $id): array => $this->issueAnswer($id, (array) ($arguments['customFieldsToReturn'] ?? ['Type', 'Stage', 'Assignee'])),
                $this->search((string) $arguments['query']),
            ), $offset, $limit),
            'get_issue_comments' => $limit > 10 ? self::error('Argument \'limit\' exceeds maximum 10.0') : array_slice(array_map(
                fn (array $comment): array => [...$comment, 'url' => self::URL.'/issue/'.$issueId.'#focus=Comments-4-'.$comment['createdAt'], 'isPinned' => false, 'updatedAt' => null, 'attachments' => []],
                $this->comments[$issueId] ?? [],
            ), $offset, $limit),
            'add_issue_comment' => $this->addComment($issueId, (string) $arguments['text']),
            'update_issue' => $this->update($issueId, (array) ($arguments['customFields'] ?? [])),
            'manage_issue_tags' => $this->tag($issueId, (string) $arguments['tag'], (string) $arguments['operation']),
            'search_articles' => $limit > 20 ? self::error('Argument \'limit\' exceeds maximum 20.0') : $this->page('articlesPage', array_values(array_map(
                fn (array $article): array => [
                    'id' => $article['id'],
                    'summary' => $article['summary'],
                    'url' => self::URL.'/articles/'.$article['id'],
                    'parentArticle' => $article['parent'] === null ? null : ['id' => $article['parent'], 'summary' => $this->articles[$article['parent']]['summary'] ?? ''],
                ],
                array_filter($this->articles, fn (array $article): bool => str_starts_with($article['id'], $this->project.'-A-')),
            )), $offset, $limit),
            'get_article' => isset($this->articles[(string) $arguments['articleId']]) ? $this->articleAnswer((string) $arguments['articleId'], (int) ($arguments['linesOffset'] ?? 0), (int) ($arguments['linesCount'] ?? 500)) : self::error('Article not found'),
            'create_article' => $this->createArticle($arguments),
            'update_article' => $this->updateArticle($arguments),
            default => self::error('Unknown tool '.$tool),
        };
    }

    /**
     * @param  list<string>|null  $fields
     * @return array<string, mixed>
     */
    private function issueAnswer(string $id, ?array $fields = null): array
    {
        $issue = $this->issues[$id];
        $answer = [
            'id' => $id,
            'url' => self::URL.'/issue/'.$id,
            'project' => explode('-', $id)[0],
            'summary' => $issue['summary'],
            'customFields' => $fields === null ? $issue['fields'] : array_intersect_key($issue['fields'], array_flip($fields)),
            'createdAt' => '2026-10-03 17:09:25',
            'updatedAt' => '2026-10-03 19:11:46',
            'resolvedAt' => $issue['fields']['Stage'] === 'Done' ? '2026-10-03 19:11:46' : null,
        ];

        if ($fields !== null) {
            return $answer;
        }

        return [
            ...$answer,
            'description' => $issue['description'],
            'parentIssue' => $issue['parent'] === null ? null : ['id' => $issue['parent'], 'summary' => $this->issues[$issue['parent']]['summary'] ?? ''],
            'tags' => $issue['tags'],
            'linkedIssueCounts' => [],
        ];
    }

    /**
     * Ids of the issues matching the query subset agentio uses.
     *
     * @return list<string>
     */
    private function search(string $query): array
    {
        preg_match_all('/(project|tag|Type|Stage|subtask of|parent for|is required for|depends on|relates to): (-?\{[^}]*\}|\S+)/', $query, $matches, PREG_SET_ORDER);
        $ids = array_keys($this->issues);

        foreach ($matches as [, $key, $value]) {
            $negated = str_starts_with($value, '-');
            $value = trim(ltrim($value, '-'), '{}');
            $ids = array_values(array_filter($ids, function (string $id) use ($key, $value, $negated): bool {
                $issue = $this->issues[$id];
                $match = match ($key) {
                    'project' => str_starts_with($id, $value.'-'),
                    'tag' => in_array($value, $issue['tags'], true),
                    'Type', 'Stage' => ($issue['fields'][$key] ?? null) === $value,
                    'subtask of' => $issue['parent'] === $value,
                    'parent for' => ($this->issues[$value]['parent'] ?? null) === $id,
                    'is required for' => in_array($id, $this->issues[$value]['dependsOn'] ?? [], true),
                    'depends on' => in_array($value, $issue['dependsOn'], true),
                    default => in_array($value, $issue['relatesTo'], true) || in_array($id, $this->issues[$value]['relatesTo'] ?? [], true),
                };

                return $negated ? ! $match : $match;
            }));
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function page(string $key, array $items, int $offset, int $limit): array
    {
        return [$key => array_slice($items, $offset, $limit), 'hasNextPage' => count($items) > $offset + $limit];
    }

    private function addComment(string $id, string $text): string
    {
        $this->comment($id, $text, $this->user);

        return 'Comment added to '.$id;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string>|string
     */
    private function update(string $id, array $fields): array|string
    {
        if (! isset($this->issues[$id])) {
            return self::error('Issue not found: '.$id);
        }

        foreach ($fields as $name => $value) {
            $this->issues[$id]['fields'][(string) $name] = is_string($value) ? $value : null;
        }

        return ['id' => $id, 'url' => self::URL.'/issue/'.$id];
    }

    /**
     * @return array<string, mixed>
     */
    private function tag(string $id, string $tag, string $operation): array
    {
        $tags = array_values(array_diff($this->issues[$id]['tags'], [$tag]));
        $this->issues[$id]['tags'] = $operation === 'add' ? [...$tags, $tag] : $tags;

        return ['issueId' => $id, 'tags' => $this->issues[$id]['tags']];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleAnswer(string $id, int $offset, int $count): array
    {
        $lines = explode("\n", $this->articles[$id]['content']);
        $parent = $this->articles[$id]['parent'];

        return [
            'id' => $id,
            'summary' => $this->articles[$id]['summary'],
            'content' => implode("\n", array_slice($lines, $offset, $count)),
            'contentMeta' => ['linesOffset' => $offset, 'linesCount' => $count, 'totalLines' => count($lines), 'hasMoreLines' => count($lines) > $offset + $count],
            'parentArticle' => $parent === null ? null : ['id' => $parent, 'summary' => $this->articles[$parent]['summary'] ?? ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    private function createArticle(array $arguments): array
    {
        $numbers = array_map(fn (string $id): int => (int) substr((string) strrchr($id, '-'), 1), array_keys($this->articles));
        $id = $this->project.'-A-'.(max([0, ...$numbers]) + 1);
        $parent = is_string($arguments['parentArticle'] ?? null) ? $arguments['parentArticle'] : null;
        $this->article($id, (string) $arguments['summary'], $parent, (string) $arguments['content']);

        return ['id' => $id, 'url' => self::URL.'/articles/'.$id];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    private function updateArticle(array $arguments): array
    {
        $id = (string) $arguments['articleId'];

        if (is_string($arguments['content'] ?? null)) {
            $this->articles[$id]['content'] = $arguments['content'];
        }

        return ['id' => $id, 'url' => self::URL.'/articles/'.$id];
    }
}
