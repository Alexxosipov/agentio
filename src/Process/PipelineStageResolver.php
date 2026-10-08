<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Illuminate\Support\Str;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;

/**
 * Derives the pipeline stage of an idea or an epic from its Type / Stage, its agent comments and its issue tree.
 *
 * Epic:
 * - Done => Done; Review => Acceptance (a human accepts and merges the epic branch);
 * - Backlog / Analysis => Architecture, or Decomposition once the architecture decision is recorded
 *   ([AGENT:DECISION] about the architecture) or stories / tasks exist;
 * - Ready / In Progress / Blocked => Development; Story review when every task is Done or a story is
 *   already in Review; Decomposition while the epic has no tasks yet.
 *
 * Idea:
 * - Backlog => Idea (Analysis once a planning session has claimed it);
 * - Analysis / In Progress / Blocked (planning) => Requirements and analysis; Architecture once an epic exists
 *   or the architecture is being decided; Decomposition once the epic has stories or tasks;
 * - Ready / Review / Done => the earliest stage of the epics it led to (Done without epics).
 *
 * Blocked is not a stage: the status is flagged and carries the reason from the last [AGENT:BLOCKED]. Nor is On Hold:
 * a paused epic keeps the stage it stopped at, a parked idea stays at its analysis; both are flagged onHold.
 */
final readonly class PipelineStageResolver
{
    private const string ARCHITECTURE = '/архитектур|architect|\bADR\b/iu';

    private const string DECOMPOSITION = '/декомпозиц|decompos/iu';

    private const int REASON_LIMIT = 300;

    public function __construct(private ReadinessGraph $graph) {}

    /**
     * Whether the stage of the issue depends on its agent comments (otherwise an empty list may be passed,
     * which saves a YouTrack request per finished issue).
     */
    public static function needsComments(Issue $issue): bool
    {
        if ($issue->hasType(IssueType::Epic)) {
            return ! $issue->hasState(State::Ready, State::InProgress, State::Review, State::Done);
        }

        return $issue->hasState(State::Analysis, State::InProgress, State::Blocked)
            || ($issue->hasState(State::Backlog) && $issue->isClaimed());
    }

    /**
     * Epics an idea led to: the epics among the issues linked to it.
     *
     * @return list<string>
     */
    public function epicsOf(Issue $idea): array
    {
        $epics = [];

        foreach ($idea->relations as $linked) {
            foreach ($linked as $id) {
                if ($id !== $idea->id && $this->graph->find($id)?->hasType(IssueType::Epic) === true) {
                    $epics[] = $id;
                }
            }
        }

        return array_values(array_unique($epics));
    }

    public function epic(Issue $epic, AgentComments $comments): PipelineStatus
    {
        $tasks = $this->taskCounts($epic->id);
        $stories = $this->ofType($epic->id, IssueType::Story);
        $storiesInReview = count(array_filter($stories, fn (Issue $story): bool => $story->hasState(State::Review, State::Done)));
        $waitingFor = $epic->hasState(State::Ready) ? $this->graph->unmetDependencies($epic->id) : [];
        $onHold = $epic->hasState(State::OnHold);

        $stage = match (true) {
            $epic->hasState(State::Done) => PipelineStage::Done,
            $epic->hasState(State::Review) => PipelineStage::Acceptance,
            ! $epic->hasState(State::Ready, State::InProgress, State::Blocked) && ! ($onHold && $tasks['total'] > 0) => $tasks['total'] > 0 || $stories !== [] || $this->decided($comments, self::ARCHITECTURE, self::DECOMPOSITION)
                ? PipelineStage::Decomposition
                : PipelineStage::Architecture,
            $tasks['total'] === 0 => PipelineStage::Decomposition,
            $tasks['done'] === $tasks['total'] || $storiesInReview > 0 => PipelineStage::StoryReview,
            default => PipelineStage::Development,
        };

        $note = match (true) {
            $onHold => 'на паузе',
            $waitingFor !== [] => 'ждёт '.implode(', ', $waitingFor),
            $stage === PipelineStage::Development && $this->graph->isEpicReady($epic->id) => 'готов к запуску',
            $stage === PipelineStage::StoryReview && $stories !== [] => sprintf('историй принято: %d из %d', $storiesInReview, count($stories)),
            $stage === PipelineStage::Acceptance => 'ждёт приёмки и слияния ветки',
            default => null,
        };

        return new PipelineStatus(
            issueId: $epic->id,
            stage: $stage,
            blocked: $epic->hasState(State::Blocked),
            blockedReason: $epic->hasState(State::Blocked) ? $this->blockedReason($comments) : null,
            tasks: $tasks,
            note: $note,
            waitingFor: $waitingFor,
            onHold: $onHold,
        );
    }

    /**
     * @param  list<PipelineStatus>  $epics  Statuses of the epics the idea led to (see epicsOf())
     */
    public function idea(Issue $idea, AgentComments $comments, array $epics = []): PipelineStatus
    {
        $tasks = ['done' => 0, 'total' => 0, 'inProgress' => 0, 'blocked' => 0];

        foreach ($epics as $epic) {
            foreach ($tasks as $key => $count) {
                $tasks[$key] = $count + $epic->tasks[$key];
            }
        }

        if ($idea->hasState(State::Backlog) && ! $idea->isClaimed() && $comments->activeClaim() === null) {
            return new PipelineStatus($idea->id, PipelineStage::Idea, note: $idea->hasTag(Tag::Parked) ? 'отложенная: ждёт системного анализа' : 'ждёт планирования');
        }

        if ($idea->hasState(State::OnHold) && $epics === []) {
            return new PipelineStatus($idea->id, PipelineStage::Analysis, note: 'отложена: анализ в статье «Идеи», ждёт решения', onHold: true);
        }

        if ($idea->hasState(State::Ready, State::Review, State::Done) && $epics !== []) {
            $blockedEpic = array_values(array_filter($epics, fn (PipelineStatus $epic): bool => $epic->blocked))[0] ?? null;

            return new PipelineStatus(
                issueId: $idea->id,
                stage: PipelineStage::earliest(...array_map(fn (PipelineStatus $epic): PipelineStage => $epic->stage, $epics)) ?? PipelineStage::Done,
                blocked: $blockedEpic !== null,
                blockedReason: $blockedEpic === null ? null : $blockedEpic->issueId.': '.($blockedEpic->blockedReason ?? 'заблокирован'),
                tasks: $tasks,
                note: count($epics) > 1 ? sprintf('эпиков: %d', count($epics)) : null,
            );
        }

        if ($idea->hasState(State::Ready, State::Review, State::Done)) {
            return new PipelineStatus($idea->id, $idea->hasState(State::Done) ? PipelineStage::Done : PipelineStage::Decomposition, tasks: $tasks);
        }

        return new PipelineStatus(
            issueId: $idea->id,
            stage: $this->planningStage($comments, $epics),
            blocked: $idea->hasState(State::Blocked),
            blockedReason: $idea->hasState(State::Blocked) ? $this->blockedReason($comments) : null,
            tasks: $tasks,
            note: 'идёт планирование',
        );
    }

    /**
     * The first paragraph of the last [AGENT:BLOCKED] comment.
     */
    public function blockedReason(AgentComments $comments): ?string
    {
        $body = $comments->last(AgentCommentKind::Blocked)?->body();

        if ($body === null || $body === '') {
            return null;
        }

        $paragraph = trim(preg_split('/\R\s*\R/u', $body, 2)[0] ?? $body);

        return Str::limit((string) preg_replace('/\s+/u', ' ', $paragraph), self::REASON_LIMIT, '…');
    }

    /**
     * @param  list<PipelineStatus>  $epics
     */
    private function planningStage(AgentComments $comments, array $epics): PipelineStage
    {
        if ($epics === []) {
            return $this->decided($comments, self::ARCHITECTURE, self::DECOMPOSITION) ? PipelineStage::Architecture : PipelineStage::Analysis;
        }

        $stage = PipelineStage::earliest(...array_map(fn (PipelineStatus $epic): PipelineStage => $epic->stage, $epics)) ?? PipelineStage::Architecture;

        return $stage->position() > PipelineStage::Decomposition->position() ? PipelineStage::Decomposition : $stage;
    }

    private function decided(AgentComments $comments, string ...$patterns): bool
    {
        foreach ($comments->ofKind(AgentCommentKind::Decision) as $decision) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $decision->body()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{done: int, total: int, inProgress: int, blocked: int}
     */
    private function taskCounts(string $rootId): array
    {
        $tasks = $this->ofType($rootId, IssueType::Task);

        return [
            'done' => count(array_filter($tasks, fn (Issue $task): bool => $task->hasState(State::Done))),
            'total' => count($tasks),
            'inProgress' => count(array_filter($tasks, fn (Issue $task): bool => $task->hasState(State::InProgress))),
            'blocked' => count(array_filter($tasks, fn (Issue $task): bool => $task->hasState(State::Blocked))),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function ofType(string $rootId, IssueType $type): array
    {
        $issues = [];

        foreach ($this->graph->descendants($rootId) as $id) {
            $issue = $this->graph->find($id);

            if ($issue?->hasType($type) === true) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
