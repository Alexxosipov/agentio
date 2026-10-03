<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Runtime\LoopLogEntry;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\YouTrack\Issue;

/**
 * The project's [AGENT:*] event feed and the tail of loop.log.
 */
final readonly class ActivityPresenter
{
    public const int EVENTS = 40;

    public const int LOOP_LINES = 150;

    public function __construct(private YouTrackSource $source, private LoopState $loop) {}

    /**
     * @return array<string, mixed>
     */
    public function events(): array
    {
        $events = $this->source->attempt(function (): array {
            $comments = $this->source->recentAgentComments(self::EVENTS);
            $issues = $this->source->attempt(fn (): array => $this->source->graph()->issues(), []);

            return array_map(fn (AgentComment $comment): array => $this->event($comment, $issues[$comment->issueId] ?? null), $comments);
        }, []);

        return ['events' => $events, 'youtrack' => $this->source->health()];
    }

    /**
     * @return array<string, mixed>
     */
    public function loopLog(): array
    {
        $path = rtrim($this->loop->logsPath(), '/\\').DIRECTORY_SEPARATOR.LoopState::LOOP_LOG;

        return [
            'exists' => is_file($path),
            'entries' => array_map(fn (LoopLogEntry $entry): array => $entry->toArray(), $this->loop->loopLog(self::LOOP_LINES)),
        ];
    }

    /**
     * @return array{issueId: string, issueSummary: string|null, issueType: string|null, url: string|null, kind: string, body: string, author: string|null, owner: string|null, createdAt: string|null}
     */
    public function event(AgentComment $comment, ?Issue $issue = null): array
    {
        return [
            'issueId' => $comment->issueId,
            'issueSummary' => $issue === null ? null : YouTrackSource::summary($issue->summary),
            'issueType' => $issue?->type(),
            'url' => $this->source->url($comment->issueId),
            'kind' => $comment->kind,
            'body' => $comment->body(),
            'author' => $comment->author,
            'owner' => $comment->owner(),
            'createdAt' => $comment->createdAt?->toIso8601String(),
        ];
    }
}
