<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Closure;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\SessionEvent;
use Obrazmisli\Agentio\Runtime\SessionKind;
use Obrazmisli\Agentio\Runtime\SessionLog;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The execution log of one issue. An idea is planned in a session of its own (plan-<IDEA>.log) and an epic is
 * worked on in one (<EPIC>.log): their log is shown whole. A story or a task is worked on inside the session of
 * its epic, by subagents described with its id ("Разработка TP-6", "Ревью STORY TP-3"): its log is the events of
 * that session that mention it or one of its subtasks.
 */
final readonly class IssueLogPresenter
{
    public const int EVENTS = 300;

    /** An epic log holds every task of the epic: the events of one task are looked for further back than a tail. */
    public const int MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(private LoopState $loop, private YouTrackSource $source) {}

    /**
     * @return array<string, mixed>
     */
    public function present(string $id): array
    {
        $issue = $this->source->attempt(fn (): ?array => $this->issue($id), null);
        [$name, $ids] = $issue === null ? $this->localSession($id) : [$issue['session'], $issue['ids']];
        $log = $name === null ? null : $this->loop->sessionLog($name);
        $log = $log?->exists() === true ? $log : null;
        $filter = $ids === [] ? null : self::mentioning($ids);
        $events = $log === null ? [] : $log->tail(self::EVENTS, self::MAX_BYTES, $filter);
        unset($issue['session'], $issue['ids']);

        return [
            'id' => $id,
            'url' => $this->source->url($id),
            'issue' => $issue,
            'session' => $log === null ? null : $this->session((string) $name, $log),
            'filtered' => $filter !== null,
            'events' => array_map(fn (SessionEvent $event): array => $event->toArray(), $events),
            'lastResult' => $log === null || $filter !== null ? null : $log->lastResult()?->toArray(),
            'youtrack' => $this->source->health(),
        ];
    }

    /**
     * The card of an issue YouTrack knows, with the session it is worked on in and the ids its events mention
     * (none: the whole log is its own).
     *
     * @return array<string, mixed>|null
     *
     * @throws YouTrackException
     */
    private function issue(string $id): ?array
    {
        $graph = $this->source->graph();
        $issue = $graph->find($id);

        if ($issue === null) {
            return null;
        }

        $epic = $graph->epicOf($id);
        [$session, $ids] = match (true) {
            $issue->isIdea() => ['plan-'.$id, []],
            $epic === null || $epic === $id => [$id, []],
            default => [$epic, [$id, ...$graph->descendants($id)]],
        };

        return [
            ...$this->source->card($issue),
            ...$this->source->claim($issue),
            'epicId' => $epic,
            'parentId' => $issue->parentId(),
            'session' => $session,
            'ids' => $ids,
        ];
    }

    /**
     * Without YouTrack only the issue's own session is known: its planning or its epic session.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function localSession(string $id): array
    {
        foreach (['plan-'.$id, $id] as $name) {
            if ($this->loop->sessionLog($name)->exists()) {
                return [$name, []];
            }
        }

        return [null, []];
    }

    /**
     * @return array<string, mixed>
     */
    private function session(string $name, SessionLog $log): array
    {
        return [
            'name' => $name,
            'kind' => SessionKind::fromName($name)->value,
            'alive' => $this->loop->session($name)->alive ?? false,
            'logPath' => basename($log->path()),
            'exists' => $log->exists(),
            'size' => $log->size(),
            'updatedAt' => $log->modifiedAt()?->toIso8601String(),
        ];
    }

    /**
     * Events that name one of the issues: in the description of their subagent, their text or their detail.
     *
     * @param  list<string>  $ids
     * @return Closure(SessionEvent): bool
     */
    private static function mentioning(array $ids): Closure
    {
        $pattern = '/(?<![A-Za-z0-9_-])(?:'.implode('|', array_map(fn (string $id): string => preg_quote($id, '/'), $ids)).')(?!\d)/';

        return fn (SessionEvent $event): bool => preg_match($pattern, $event->subagent.' '.$event->text.' '.$event->detail) === 1;
    }
}
