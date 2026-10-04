<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Closure;
use Illuminate\Support\Facades\Cache;
use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * YouTrack data for the dashboard: the IssueRepository behind a short cache, so that polling
 * browsers do not hit YouTrack on every request. Failures are cached as well (for at least
 * FAILURE_TTL seconds) and reported through health() instead of breaking the page.
 *
 * Only the decoded JSON answers are cached, never objects: cache stores of applications with
 * `cache.serializable_classes` = false (the Laravel 13 default) turn cached objects into
 * __PHP_Incomplete_Class.
 */
final class YouTrackSource
{
    /**
     * Minimum seconds a failure is cached: an unreachable YouTrack answers only after its timeouts and retries,
     * which should not hold up every poll of every open dashboard.
     */
    public const int FAILURE_TTL = 30;

    private ?string $error = null;

    private ?ReadinessGraph $graph = null;

    private ?string $generation = null;

    /** @var array<string, AgentComments> */
    private array $comments = [];

    private readonly int $ttl;

    /**
     * @param  int|null  $ttl  Seconds to cache YouTrack responses, 0 disables the cache (default: agentio.ui.cache)
     */
    public function __construct(private readonly IssueRepository $repository, ?int $ttl = null)
    {
        $this->ttl = $ttl ?? (int) config('agentio.ui.cache', 5);
    }

    public function isConfigured(): bool
    {
        return $this->repository->client()->isConfigured();
    }

    public function project(): string
    {
        return $this->repository->project();
    }

    public function url(string $id): ?string
    {
        return $this->isConfigured() ? $this->repository->url($id) : null;
    }

    /**
     * The issue list of the project in the YouTrack web UI.
     */
    public function projectUrl(): ?string
    {
        return $this->isConfigured()
            ? $this->repository->client()->baseUrl().'/issues?q='.rawurlencode('project: '.$this->project())
            : null;
    }

    /**
     * Run a YouTrack read; when YouTrack is not configured or fails, remember why and return the fallback.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @param  TValue  $fallback
     * @return TValue
     */
    public function attempt(Closure $read, mixed $fallback): mixed
    {
        if (! $this->isConfigured()) {
            return $fallback;
        }

        try {
            return $read();
        } catch (YouTrackException $exception) {
            $this->error ??= $exception->getMessage();

            return $fallback;
        }
    }

    /**
     * @return array{configured: bool, ok: bool, error: string|null}
     */
    public function health(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'ok' => $this->isConfigured() && $this->error === null,
            'error' => $this->error,
        ];
    }

    /**
     * Forget every cached YouTrack answer (after the dashboard changed issues), here and in the cache store.
     */
    public function flush(): void
    {
        $this->graph = null;
        $this->comments = [];
        $this->generation = bin2hex(random_bytes(8));
        Cache::forever($this->generationKey(), $this->generation);
    }

    /**
     * Every issue of the project.
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function issues(): array
    {
        return array_map(Issue::fromApi(...), $this->rows('issues', fn (): array => $this->client()->searchIssues($this->repository->projectQuery())));
    }

    /**
     * The readiness graph of the project; linked issues of other projects are loaded (and cached) on demand.
     *
     * @throws YouTrackException
     */
    public function graph(): ReadinessGraph
    {
        return $this->graph ??= new ReadinessGraph($this->issues(), $this->external(...));
    }

    /**
     * The [AGENT:*] comments of an issue, oldest first.
     *
     * @throws YouTrackException
     */
    public function agentComments(string $id): AgentComments
    {
        return $this->comments[$id] ??= AgentComments::fromComments(array_map(
            fn (array $raw): Comment => Comment::fromApi($raw, $id),
            $this->rows('comments:'.$id, fn (): array => $this->client()->comments($id)),
        ));
    }

    /**
     * The latest [AGENT:*] comments of the project, or of the given issues, newest first.
     *
     * @param  list<string>|null  $ids
     * @return list<AgentComment>
     *
     * @throws YouTrackException
     */
    public function recentAgentComments(int $limit, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $ids === null ? $this->repository->projectQuery() : IssueRepository::idQuery($ids);
        $activities = $this->rows('recent:'.$limit.':'.hash('xxh128', $query), fn (): array => $this->client()->commentActivities($query, $limit));

        return array_values(array_filter(array_map(AgentComment::fromComment(...), IssueRepository::commentsFromActivities($activities))));
    }

    /**
     * The claim of a claimed issue: owner and start time of its active [AGENT:START].
     *
     * @return array{owner: string|null, since: string|null}
     *
     * @throws YouTrackException
     */
    public function claim(Issue $issue): array
    {
        $claim = $issue->isClaimed() ? $this->agentComments($issue->id)->activeClaim() : null;

        return ['owner' => $claim?->owner(), 'since' => $claim?->createdAt?->toIso8601String()];
    }

    /**
     * The short representation of an issue used across the dashboard.
     *
     * @return array{id: string, summary: string, type: string|null, state: string|null, url: string|null, claimed: bool}
     */
    public function card(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'summary' => self::summary($issue->summary),
            'type' => $issue->type(),
            'state' => $issue->state(),
            'url' => $this->url($issue->id),
            'claimed' => $issue->isClaimed(),
        ];
    }

    /**
     * A summary without the "[EPIC] " / "[TASK] " prefix the agents put in front of it.
     */
    public static function summary(string $summary): string
    {
        return (string) preg_replace('/^\[(IDEA|EPIC|STORY|TASK)\]\s*/i', '', $summary);
    }

    /**
     * @throws YouTrackException
     */
    private function external(string $id): ?Issue
    {
        try {
            $raw = $this->remember('issue:'.$id, fn (): array => $this->client()->issue($id, Client::ISSUE_FIELDS));
        } catch (YouTrackException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        return is_array($raw) ? Issue::fromApi($raw) : null;
    }

    private function client(): Client
    {
        return $this->repository->client();
    }

    /**
     * A cached list of YouTrack entities (decoded JSON objects).
     *
     * @param  Closure(): array<array-key, mixed>  $read
     * @return list<array<array-key, mixed>>
     *
     * @throws YouTrackException
     */
    private function rows(string $key, Closure $read): array
    {
        $rows = $this->remember($key, $read);

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * Read through the cache. Only the decoded JSON of YouTrack is cached (arrays and scalars), so any cache
     * store works, including ones that refuse to unserialize objects (`cache.serializable_classes`).
     *
     * @param  Closure(): mixed  $read
     *
     * @throws YouTrackException
     */
    private function remember(string $key, Closure $read): mixed
    {
        if ($this->ttl <= 0) {
            return $read();
        }

        $cacheKey = $this->cacheKey($key);
        $envelope = Cache::get($cacheKey);

        if (! is_array($envelope) || ! (array_key_exists('value', $envelope) || is_string($envelope['error'] ?? null))) {
            try {
                $envelope = ['value' => $read()];
            } catch (YouTrackException $exception) {
                $envelope = ['error' => $exception->getMessage(), 'status' => $exception->status];
            }

            Cache::put($cacheKey, $envelope, isset($envelope['error']) ? max($this->ttl, self::FAILURE_TTL) : $this->ttl);
        }

        if (is_string($envelope['error'] ?? null)) {
            throw new YouTrackException($envelope['error'], is_int($envelope['status'] ?? null) ? $envelope['status'] : null);
        }

        return $envelope['value'] ?? null;
    }

    /**
     * Cache keys carry a generation, so that flush() forgets every answer at once on any cache store.
     */
    private function cacheKey(string $key): string
    {
        $generation = $this->generation ??= (string) (Cache::get($this->generationKey()) ?? '0');

        return $this->prefix().':'.$generation.':'.$key;
    }

    private function generationKey(): string
    {
        return $this->prefix().':generation';
    }

    private function prefix(): string
    {
        return 'agentio:'.hash('xxh128', $this->repository->client()->baseUrl().'|'.$this->project());
    }
}
