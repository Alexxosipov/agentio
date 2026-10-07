<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack\Mcp;

use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The tools of the YouTrack MCP server that agentio uses, with their paging limits
 * (search_issues and search_articles return at most 20 items a page, get_issue_comments 10).
 */
final readonly class Tools
{
    public const int ISSUE_PAGE = 20;

    public const int ARTICLE_PAGE = 20;

    public const int COMMENT_PAGE = 10;

    public function __construct(private McpClient $client) {}

    public function client(): McpClient
    {
        return $this->client;
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Link to an issue in the YouTrack web UI.
     */
    public function issueUrl(string $id): string
    {
        return $this->client->baseUrl().'/issue/'.rawurlencode($id);
    }

    /**
     * The user the token belongs to: {login, name, email}.
     *
     * @return array{login: string, name: string, email: string}
     *
     * @throws YouTrackException When the URL is wrong, YouTrack is unreachable or the token is rejected
     */
    public function currentUser(): array
    {
        $user = $this->client->call('get_current_user');

        if (! is_string($user['login'] ?? null) || $user['login'] === '') {
            throw new YouTrackException('YouTrack MCP get_current_user returned no user: is the URL the YouTrack instance itself?');
        }

        return [
            'login' => $user['login'],
            'name' => is_string($user['name'] ?? null) ? $user['name'] : '',
            'email' => is_string($user['email'] ?? null) ? $user['email'] : '',
        ];
    }

    /**
     * The project with the key, or null when it does not exist or the token cannot see it.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws YouTrackException
     */
    public function project(string $key): ?array
    {
        try {
            return $this->client->call('get_project', ['projectKey' => $key]);
        } catch (YouTrackException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Issues matching a YouTrack query, every page: {id, summary, customFields: {name: value}, …}.
     *
     * @param  list<string>  $fields  Custom fields to return
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function searchIssues(string $query, array $fields = [IssueType::FIELD, State::FIELD]): array
    {
        return $this->client->paginate('search_issues', ['query' => $query, 'customFieldsToReturn' => $fields], self::ISSUE_PAGE, 'issuesPage');
    }

    /**
     * Ids of the issues matching a YouTrack query.
     *
     * @return list<string>
     *
     * @throws YouTrackException
     */
    public function issueIds(string $query): array
    {
        return array_values(array_filter(array_map(
            fn (array $issue): ?string => is_string($issue['id'] ?? null) ? $issue['id'] : null,
            $this->searchIssues($query, []),
        )));
    }

    /**
     * An issue as get_issue returns it: summary, description, customFields, tags, parentIssue, …
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function issue(string $id): array
    {
        return $this->client->call('get_issue', ['issueId' => $id, 'recentCommentsCount' => 0]);
    }

    /**
     * An issue with its parent, children and dependencies (three MCP calls), or null when it does not exist.
     *
     * @throws YouTrackException
     */
    public function issueWithLinks(string $id): ?Issue
    {
        try {
            $raw = $this->issue($id);
        } catch (YouTrackException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        return Issue::fromMcp($raw, [
            'parent for' => self::byNumber($this->issueIds('subtask of: '.$id)),
            'depends on' => self::byNumber($this->issueIds('is required for: '.$id)),
        ]);
    }

    /**
     * Issue ids ordered by their number (TP-2 before TP-10).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function byNumber(array $ids): array
    {
        usort($ids, fn (string $left, string $right): int => [self::number($left), $left] <=> [self::number($right), $right]);

        return $ids;
    }

    /**
     * The number of an issue or article id: 12 for "TP-12", 3 for "TP-A-3".
     */
    public static function number(string $id): int
    {
        return (int) substr((string) strrchr($id, '-'), 1);
    }

    /**
     * Comments of an issue, oldest first.
     *
     * @return list<Comment>
     *
     * @throws YouTrackException
     */
    public function comments(string $id): array
    {
        return array_map(
            fn (array $raw): Comment => Comment::fromMcp($raw, $id),
            $this->client->paginate('get_issue_comments', ['issueId' => $id], self::COMMENT_PAGE),
        );
    }

    /**
     * Create an issue, optionally as a subtask of a parent; returns its readable id (e.g. "TP-12").
     *
     * @param  array<string, string>  $fields  Custom fields, e.g. ["Type" => "Task", "Stage" => "Ready"]
     *
     * @throws YouTrackException
     */
    public function createIssue(string $project, string $summary, string $description, ?string $parent = null, array $fields = []): string
    {
        $issue = $this->client->call('create_issue', array_filter([
            'project' => $project,
            'summary' => $summary,
            'description' => $description,
            'parentIssue' => $parent,
            'customFields' => $fields === [] ? null : (object) $fields,
        ], fn (mixed $value): bool => $value !== null));

        // YouTrack answers {"createdIssue": {"id": "TP-12", "url": …}, "updatedFields": […], …}.
        foreach ([$issue['createdIssue'] ?? null, $issue] as $candidate) {
            foreach (is_array($candidate) ? ['idReadable', 'id', 'issueId'] : [] as $key) {
                if (is_string($candidate[$key] ?? null) && preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $candidate[$key]) === 1) {
                    return $candidate[$key];
                }
            }
        }

        $text = is_string($issue['text'] ?? null) ? $issue['text'] : '';

        return preg_match('/\b'.preg_quote($project, '/').'-\d+\b/', $text, $match) === 1
            ? $match[0]
            : throw new YouTrackException('YouTrack MCP create_issue returned no issue id.');
    }

    /**
     * @throws YouTrackException
     */
    public function addComment(string $id, string $text): void
    {
        $this->client->callText('add_issue_comment', ['issueId' => $id, 'text' => $text]);
    }

    /**
     * Set custom fields of an issue, e.g. ["Stage" => "Ready"].
     *
     * @param  array<string, string|null>  $fields
     *
     * @throws YouTrackException
     */
    public function updateFields(string $id, array $fields): void
    {
        $this->client->callText('update_issue', ['issueId' => $id, 'customFields' => (object) $fields]);
    }

    /**
     * @throws YouTrackException
     */
    public function addTag(string $id, string $tag): void
    {
        $this->client->callText('manage_issue_tags', ['issueId' => $id, 'tag' => $tag, 'operation' => 'add']);
    }

    /**
     * @throws YouTrackException
     */
    public function removeTag(string $id, string $tag): void
    {
        $this->client->callText('manage_issue_tags', ['issueId' => $id, 'tag' => $tag, 'operation' => 'remove']);
    }

    /**
     * Articles matching a YouTrack query, every page: {id, summary, parentArticle: {id, summary}|null, …}.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function searchArticles(string $query): array
    {
        return $this->client->paginate('search_articles', ['query' => $query], self::ARTICLE_PAGE, 'articlesPage');
    }

    /**
     * The full content of an article (get_article returns long articles in slices of lines).
     *
     * @throws YouTrackException
     */
    public function articleContent(string $id): string
    {
        $lines = [];

        for ($offset = 0; ;) {
            $article = $this->client->call('get_article', ['articleId' => $id, 'linesOffset' => $offset, 'linesCount' => 500]);
            $content = is_string($article['content'] ?? null) ? $article['content'] : '';
            $slice = $content === '' ? [] : explode("\n", $content);
            array_push($lines, ...$slice);
            $meta = is_array($article['contentMeta'] ?? null) ? $article['contentMeta'] : [];

            if (($meta['hasMoreLines'] ?? false) !== true || $slice === []) {
                return implode("\n", $lines);
            }

            $offset += count($slice);
        }
    }

    /**
     * Create an article, optionally under a parent article; returns its readable id (e.g. "TP-A-1").
     *
     * @throws YouTrackException
     */
    public function createArticle(string $project, string $summary, string $content, ?string $parentArticle = null): string
    {
        $article = $this->client->call('create_article', array_filter([
            'project' => $project,
            'summary' => $summary,
            'content' => $content,
            'parentArticle' => $parentArticle,
        ], fn (?string $value): bool => $value !== null));

        foreach ([$article['createdArticle'] ?? null, $article] as $candidate) {
            foreach (is_array($candidate) ? ['id', 'idReadable', 'articleId'] : [] as $key) {
                if (is_string($candidate[$key] ?? null) && $candidate[$key] !== '') {
                    return $candidate[$key];
                }
            }
        }

        $text = is_string($article['text'] ?? null) ? $article['text'] : '';

        return preg_match('/\b[A-Z][A-Z0-9_]*-A-\d+\b/', $text, $match) === 1
            ? $match[0]
            : throw new YouTrackException('YouTrack MCP create_article returned no article id.');
    }

    /**
     * Replace the content of an article.
     *
     * @throws YouTrackException
     */
    public function updateArticleContent(string $id, string $content): void
    {
        $this->client->callText('update_article', ['articleId' => $id, 'content' => $content]);
    }
}
