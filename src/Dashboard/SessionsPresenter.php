<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\Session;
use Obrazmisli\Agentio\Runtime\SessionEvent;
use Obrazmisli\Agentio\Runtime\SessionEventType;
use Obrazmisli\Agentio\Runtime\SessionKind;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * "Who works on what right now": live sessions of the loop with their latest events, the most recent
 * finished sessions, and the issues agents have claimed.
 */
final readonly class SessionsPresenter
{
    public const int EVENTS = 15;

    public const int RECENT = 3;

    public function __construct(
        private LoopState $loop,
        private YouTrackSource $source,
        private PipelinePresenter $pipeline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $live = $this->loop->runningSessions();
        $liveNames = array_map(fn (Session $session): string => $session->name, $live);
        $recent = array_values(array_filter($this->finishedSessions(), fn (Session $session): bool => ! in_array($session->name, $liveNames, true)));

        return [
            'sessions' => array_map($this->session(...), $live),
            'recent' => array_map($this->session(...), array_slice($recent, 0, self::RECENT)),
            'claimed' => $this->source->attempt($this->claimed(...), []),
            'youtrack' => $this->source->health(),
        ];
    }

    /**
     * Sessions that are not running: stale pid files and session logs without a pid file, newest first.
     *
     * @return list<Session>
     */
    public function finishedSessions(): array
    {
        $sessions = array_values(array_filter($this->loop->sessions(), fn (Session $session): bool => ! $session->alive));
        $known = array_map(fn (Session $session): string => $session->name, $sessions);

        foreach (glob(rtrim($this->loop->logsPath(), '/\\').DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            $name = basename($file, '.log');

            if (preg_match('/^(plan-)?[A-Za-z][A-Za-z0-9_]*-\d+$/', $name) === 1 && ! in_array($name, $known, true)) {
                $kind = SessionKind::fromName($name);
                $sessions[] = new Session($name, $kind === SessionKind::Plan ? substr($name, 5) : $name, $kind, null, false, $file);
            }
        }

        $modified = fn (Session $session): int => is_file($session->logPath) ? (int) filemtime($session->logPath) : 0;
        usort($sessions, fn (Session $a, Session $b): int => $modified($b) <=> $modified($a));

        return $sessions;
    }

    /**
     * @return array<string, mixed>
     */
    private function session(Session $session): array
    {
        $log = $this->loop->sessionLog($session->name);
        $result = $log->lastResult();
        $events = array_values(array_filter(
            $log->tail(self::EVENTS * 4),
            fn (SessionEvent $event): bool => $event->type !== SessionEventType::Result,
        ));

        return [
            ...$session->toArray(),
            'logPath' => basename($session->logPath),
            'issue' => $this->source->attempt(fn (): ?array => $this->issue($session), null),
            'events' => array_map(fn (SessionEvent $event): array => $event->toArray(), array_slice($events, -self::EVENTS)),
            'lastResult' => $result?->toArray(),
            'log' => [
                'exists' => $log->exists(),
                'size' => $log->size(),
                'updatedAt' => $log->modifiedAt()?->toIso8601String(),
            ],
        ];
    }

    /**
     * The issue of a session with its claim, pipeline stage and the tasks being worked on.
     *
     * @return array<string, mixed>|null
     *
     * @throws YouTrackException
     */
    private function issue(Session $session): ?array
    {
        $graph = $this->source->graph();
        $issue = $graph->find($session->issueId);

        if ($issue === null) {
            return null;
        }

        $current = [];

        if ($session->kind === SessionKind::Epic) {
            foreach ($graph->descendants($issue->id) as $id) {
                $child = $graph->find($id);

                if ($child !== null && ($child->isClaimed() || ($child->hasType(IssueType::Task) && $child->hasState(State::InProgress)))) {
                    $current[] = [...$this->source->card($child), ...$this->source->claim($child)];
                }
            }
        }

        return [
            ...$this->source->card($issue),
            ...$this->source->claim($issue),
            'status' => $this->pipeline->statusOf($issue)?->toArray(),
            'current' => $current,
        ];
    }

    /**
     * Issues with the agent-claimed tag and their owners.
     *
     * @return list<array<string, mixed>>
     *
     * @throws YouTrackException
     */
    private function claimed(): array
    {
        $graph = $this->source->graph();
        $claimed = array_values(array_filter($graph->issues(), fn (Issue $issue): bool => $issue->isClaimed()));

        return array_map(fn (Issue $issue): array => [
            ...$this->source->card($issue),
            ...$this->source->claim($issue),
            'epicId' => $graph->epicOf($issue->id),
            'updatedAt' => $issue->updatedAt?->toIso8601String(),
        ], $claimed);
    }
}
