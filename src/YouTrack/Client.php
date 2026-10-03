<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A thin YouTrack REST client (https://www.jetbrains.com/help/youtrack/devportal/youtrack-rest-api.html).
 *
 * Low-level calls (get/post/delete/paginate) take paths relative to `<url>/api/`. The convenience
 * methods return the decoded JSON as is; IssueRepository turns issues and comments into objects.
 */
final readonly class Client
{
    /** Fields of an issue that Issue::fromApi() understands. */
    public const string ISSUE_FIELDS = 'idReadable,summary,created,updated,resolved,'
        .'customFields(name,value(name,login,fullName,presentation,text)),tags(id,name),'
        .'links(direction,linkType(name,sourceToTarget,targetToSource),issues(idReadable))';

    /** Fields of a comment that Comment::fromApi() understands. */
    public const string COMMENT_FIELDS = 'id,text,created,author(login,fullName)';

    /** Fields of an article returned by the article helpers. */
    public const string ARTICLE_FIELDS = 'id,idReadable,summary,content,updated,parentArticle(id,idReadable),project(id,shortName)';

    public const int PAGE_SIZE = 200;

    public function __construct(
        private ?string $url,
        private ?string $token,
        private int $timeout = 30,
        private int $retries = 2,
    ) {}

    /**
     * Whether both the URL and the token are set.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && (string) $this->token !== '';
    }

    /**
     * The YouTrack instance URL without a trailing slash, e.g. https://example.youtrack.cloud.
     */
    public function baseUrl(): string
    {
        return rtrim((string) $this->url, '/');
    }

    /**
     * Link to an issue in the YouTrack web UI.
     */
    public function issueUrl(string $id): string
    {
        return $this->baseUrl().'/issue/'.rawurlencode($id);
    }

    /**
     * Link to a knowledge base article in the YouTrack web UI.
     */
    public function articleUrl(string $id): string
    {
        return $this->baseUrl().'/articles/'.rawurlencode($id);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->send('POST', $path, $query, $body);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->send('DELETE', $path, $query);
    }

    /**
     * Fetch every page of a collection endpoint ($top / $skip).
     *
     * @param  array<string, scalar|null>  $query
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function paginate(string $path, array $query = [], int $pageSize = self::PAGE_SIZE): array
    {
        $items = [];

        for ($skip = 0; ; $skip += $pageSize) {
            $page = $this->get($path, [...$query, '$top' => $pageSize, '$skip' => $skip]);

            foreach ($page as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (count($page) < $pageSize) {
                return $items;
            }
        }
    }

    /**
     * Issues matching a YouTrack search query, e.g. "project: TP Type: Epic".
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function searchIssues(string $query, string $fields = self::ISSUE_FIELDS): array
    {
        return $this->paginate('issues', ['query' => $query, 'fields' => $fields]);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function issue(string $id, string $fields = self::ISSUE_FIELDS.',description'): array
    {
        return $this->get('issues/'.rawurlencode($id), ['fields' => $fields]);
    }

    /**
     * Comments of an issue, oldest first.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function comments(string $issueId, string $fields = self::COMMENT_FIELDS): array
    {
        return $this->paginate('issues/'.rawurlencode($issueId).'/comments', ['fields' => $fields]);
    }

    /**
     * The latest comment activities of the issues matching a query, newest first.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function commentActivities(string $issueQuery, int $limit = 50): array
    {
        $activities = $this->get('activities', [
            'categories' => 'CommentsCategory',
            'issueQuery' => $issueQuery,
            'reverse' => 'true',
            'fields' => 'id,timestamp,author(login,fullName),added(id,text,created,issue(idReadable,summary))',
            '$top' => $limit,
        ]);

        return array_values(array_filter($activities, is_array(...)));
    }

    /**
     * Knowledge base articles matching a query (all articles when the query is empty).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function articles(string $query = '', string $fields = self::ARTICLE_FIELDS): array
    {
        return $this->paginate('articles', array_filter(['query' => $query, 'fields' => $fields], fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function article(string $id, string $fields = self::ARTICLE_FIELDS): array
    {
        return $this->get('articles/'.rawurlencode($id), ['fields' => $fields]);
    }

    /**
     * Create a knowledge base article. The parent is referenced by its database id (e.g. "155-12").
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function createArticle(string $projectId, string $summary, string $content, ?string $parentId = null): array
    {
        $body = ['project' => ['id' => $projectId], 'summary' => $summary, 'content' => $content];

        if ($parentId !== null) {
            $body['parentArticle'] = ['id' => $parentId];
        }

        return $this->post('articles', $body, ['fields' => self::ARTICLE_FIELDS]);
    }

    /**
     * Update the summary and/or content of an article.
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function updateArticle(string $id, ?string $summary = null, ?string $content = null): array
    {
        return $this->post('articles/'.rawurlencode($id), array_filter(
            ['summary' => $summary, 'content' => $content],
            fn (?string $value): bool => $value !== null,
        ), ['fields' => self::ARTICLE_FIELDS]);
    }

    /**
     * A project by its short name, or null when it does not exist or is not visible.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws YouTrackException
     */
    public function project(string $shortName, string $fields = 'id,shortName,name'): ?array
    {
        foreach ($this->paginate('admin/projects', ['fields' => $fields, 'query' => $shortName]) as $project) {
            if (($project['shortName'] ?? null) === $shortName) {
                return $project;
            }
        }

        return null;
    }

    /**
     * The database id (e.g. "0-3") of a project given its short name.
     *
     * @throws YouTrackException
     */
    public function projectId(string $shortName): string
    {
        $project = $this->project($shortName);

        if ($project === null || ! is_string($project['id'] ?? null)) {
            throw YouTrackException::projectNotFound($shortName);
        }

        return $project['id'];
    }

    /**
     * Custom fields attached to a project, with their field and bundle.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function projectCustomFields(string $projectId): array
    {
        return $this->paginate('admin/projects/'.rawurlencode($projectId).'/customFields', [
            'fields' => 'id,$type,canBeEmpty,emptyFieldText,field(id,name,fieldType(id)),bundle(id,name,$type),defaultValues(id,name)',
        ]);
    }

    /**
     * Attach a global custom field to a project, e.g. type "StateProjectCustomField" with bundle type "StateBundle",
     * optionally with a default value (an element of the bundle).
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function attachCustomField(string $projectId, string $fieldId, string $type, ?string $bundleId = null, ?string $bundleType = null, bool $canBeEmpty = true, ?string $defaultValueId = null): array
    {
        $body = ['$type' => $type, 'field' => ['id' => $fieldId], 'canBeEmpty' => $canBeEmpty];

        if ($bundleId !== null) {
            $body['bundle'] = array_filter(['id' => $bundleId, '$type' => $bundleType], fn (?string $value): bool => $value !== null);
        }

        if ($defaultValueId !== null) {
            $body['defaultValues'] = [array_filter(['id' => $defaultValueId, '$type' => $bundleType === null ? null : $bundleType.'Element'], fn (?string $value): bool => $value !== null)];
        }

        return $this->post('admin/projects/'.rawurlencode($projectId).'/customFields', $body, [
            'fields' => 'id,$type,field(id,name),bundle(id,name)',
        ]);
    }

    /**
     * Global custom field definitions.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function customFields(): array
    {
        return $this->paginate('admin/customFieldSettings/customFields', [
            'fields' => 'id,name,fieldType(id),fieldDefaults(bundle(id)),instances(project(id),bundle(id))',
        ]);
    }

    /**
     * Change the settings of a project custom field, e.g. its bundle, default values or canBeEmpty.
     *
     * @param  array<string, mixed>  $changes
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function updateProjectCustomField(string $projectId, string $projectFieldId, string $type, array $changes): array
    {
        return $this->post('admin/projects/'.rawurlencode($projectId).'/customFields/'.rawurlencode($projectFieldId), ['$type' => $type, ...$changes], [
            'fields' => 'id,$type,canBeEmpty,field(id,name),bundle(id,name),defaultValues(id,name)',
        ]);
    }

    /**
     * Whether the project has at least one issue.
     *
     * @throws YouTrackException
     */
    public function projectHasIssues(string $shortName): bool
    {
        return $this->get('issues', ['query' => 'project: '.$shortName, 'fields' => 'id', '$top' => 1]) !== [];
    }

    /**
     * Create a global custom field, e.g. type "state[1]" or "enum[1]".
     *
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function createCustomField(string $name, string $fieldType): array
    {
        return $this->post('admin/customFieldSettings/customFields', [
            'name' => $name,
            'fieldType' => ['id' => $fieldType],
            'isAutoAttached' => false,
        ], ['fields' => 'id,name,fieldType(id)']);
    }

    /**
     * Bundles of a kind: "state", "enum", "ownedField", "version", "build" or "user".
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function bundles(string $kind): array
    {
        return $this->paginate('admin/customFieldSettings/bundles/'.$kind, [
            'fields' => 'id,name,values(id,name,ordinal,isResolved,archived)',
        ]);
    }

    /**
     * Create a bundle with values; a state value is {"name": "Done", "isResolved": true}.
     *
     * @param  list<array<string, mixed>>  $values
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function createBundle(string $kind, string $name, array $values = []): array
    {
        return $this->post('admin/customFieldSettings/bundles/'.$kind, ['name' => $name, 'values' => $values], [
            'fields' => 'id,name,values(id,name,ordinal,isResolved)',
        ]);
    }

    /**
     * Add a value to an existing bundle.
     *
     * @param  array<string, mixed>  $value
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function addBundleValue(string $kind, string $bundleId, array $value): array
    {
        return $this->post('admin/customFieldSettings/bundles/'.$kind.'/'.rawurlencode($bundleId).'/values', $value, [
            'fields' => 'id,name,ordinal,isResolved',
        ]);
    }

    /**
     * Tags visible to the token, optionally filtered by a name query.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function tags(string $query = ''): array
    {
        return $this->paginate('tags', array_filter(['fields' => 'id,name', 'query' => $query], fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function createTag(string $name): array
    {
        return $this->post('tags', ['name' => $name], ['fields' => 'id,name']);
    }

    /**
     * Saved searches visible to the token.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    public function savedQueries(): array
    {
        return $this->paginate('savedQueries', ['fields' => 'id,name,query']);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    public function createSavedQuery(string $name, string $query): array
    {
        return $this->post('savedQueries', ['name' => $name, 'query' => $query], ['fields' => 'id,name,query']);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<array-key, mixed>|null  $body
     * @return array<array-key, mixed>
     *
     * @throws YouTrackException
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            throw YouTrackException::notConfigured();
        }

        $path = ltrim($path, '/');
        $options = ['query' => array_filter($query, fn (mixed $value): bool => $value !== null)];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->request()->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw YouTrackException::connectionFailed($method, $path, $exception);
        }

        if ($response->failed()) {
            throw YouTrackException::requestFailed($method, $path, $response->status(), $this->reason($response));
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl().'/api/')
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->retry(
                $this->retries + 1,
                fn (int $attempt): int => $attempt * 500,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)),
                throw: false,
            );
    }

    private function reason(Response $response): string
    {
        $description = $response->json('error_description') ?? $response->json('error');

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $body = trim($response->body());

        return $body === '' ? $response->reason() : mb_substr($body, 0, 500);
    }
}
