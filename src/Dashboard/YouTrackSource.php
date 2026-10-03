<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * YouTrack data for the dashboard: the IssueRepository behind a short cache, so that polling
 * browsers do not hit YouTrack on every request. Failures are cached as well (for at least
 * FAILURE_TTL seconds) and reported through health() instead of breaking the page.
 *
 * Entries are stored as serialized strings and restored with an explicit list of allowed classes:
 * cache stores of applications with `cache.serializable_classes` = false (the Laravel 13 default)
 * would otherwise turn the cached objects into __PHP_Incomplete_Class.
 */
final class YouTrackSource
{
    /**
     * Minimum seconds a failure is cached: an unreachable YouTrack answers only after its timeouts and retries,
     * which should not hold up every poll of every open dashboard.
     */
    public const int FAILURE_TTL = 30;

    /** Classes a cached YouTrack answer may contain. */
    private const array CACHED_CLASSES = [Issue::class, Comment::class, AgentComment::class, CarbonImmutable::class];

    private ?string $error = null;

    private ?ReadinessGraph $graph = null;

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
     * Every issue of the project.
     *
     * @return list<Issue>
     *
     * @throws YouTrackException
     */
    public function issues(): array
    {
        $issues = $this->remember('issues', fn (): array => $this->repository->projectIssues());

        return is_array($issues) ? array_values(array_filter($issues, fn (mixed $issue): bool => $issue instanceof Issue)) : [];
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
        if (! isset($this->comments[$id])) {
            $comments = $this->remember('comments:'.$id, fn (): array => $this->repository->comments($id));

            $this->comments[$id] = AgentComments::fromComments(
                is_array($comments) ? array_filter($comments, fn (mixed $comment): bool => $comment instanceof Comment) : [],
            );
        }

        return $this->comments[$id];
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
        $key = 'recent:'.$limit.($ids === null ? '' : ':'.hash('xxh128', implode(',', $ids)));
        $comments = $this->remember($key, fn (): array => $ids === null
            ? $this->repository->recentAgentComments($limit)
            : $this->repository->recentAgentCommentsOf($ids, $limit));

        return is_array($comments) ? array_values(array_filter($comments, fn (mixed $comment): bool => $comment instanceof AgentComment)) : [];
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
            $issue = $this->remember('issue:'.$id, fn (): Issue => $this->repository->find($id));
        } catch (YouTrackException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        return $issue instanceof Issue ? $issue : null;
    }

    /**
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
        $envelope = self::unpack(Cache::get($cacheKey));

        if ($envelope === null) {
            try {
                $envelope = ['value' => $read()];
            } catch (YouTrackException $exception) {
                $envelope = ['error' => $exception->getMessage(), 'status' => $exception->status];
            }

            Cache::put($cacheKey, serialize($envelope), isset($envelope['error']) ? max($this->ttl, self::FAILURE_TTL) : $this->ttl);
        }

        if (is_string($envelope['error'] ?? null)) {
            throw new YouTrackException($envelope['error'], is_int($envelope['status'] ?? null) ? $envelope['status'] : null);
        }

        return $envelope['value'] ?? null;
    }

    /**
     * A cached envelope, or null for a missing, foreign or corrupt entry.
     *
     * @return array<array-key, mixed>|null
     */
    private static function unpack(mixed $cached): ?array
    {
        $envelope = is_string($cached) ? @unserialize($cached, ['allowed_classes' => self::CACHED_CLASSES]) : null;

        return is_array($envelope) ? $envelope : null;
    }

    private function cacheKey(string $key): string
    {
        return 'agentio:'.hash('xxh128', $this->repository->client()->baseUrl().'|'.$this->project()).':'.$key;
    }
}
