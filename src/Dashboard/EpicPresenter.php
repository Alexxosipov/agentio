<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\Process\AgentComment;
use Obrazmisli\Agentio\Process\TreeNode;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * One epic in detail: its STORY -> TASK tree with claims, dependencies and readiness, progress by story, the epics
 * that wait for it and the [AGENT:*] feed of the whole tree.
 */
final readonly class EpicPresenter
{
    public const int EVENTS = 60;

    public function __construct(
        private YouTrackSource $source,
        private PipelinePresenter $pipeline,
        private ActivityPresenter $activity,
    ) {}

    /**
     * @return array<string, mixed>|null Null when YouTrack answers but has no such issue
     */
    public function present(string $id): ?array
    {
        $found = true;
        $epic = $this->source->attempt(function () use ($id, &$found): ?array {
            $issue = $this->source->graph()->find($id);
            $found = $issue !== null;

            return $issue === null ? null : $this->epic($issue);
        }, null);

        if (! $found) {
            return null;
        }

        return ['epic' => $epic, 'youtrack' => $this->source->health()];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws YouTrackException
     */
    private function epic(Issue $epic): array
    {
        $graph = $this->source->graph();
        $tree = $graph->tree($epic->id);
        $descendants = $graph->descendants($epic->id);

        $stories = [];
        $waiting = [];

        foreach ($tree->flatten() as $node) {
            if ($node->issue->hasType(IssueType::Story)) {
                $progress = $graph->progress($node->issue->id);
                $stories[] = [
                    ...$this->source->card($node->issue),
                    'done' => $progress[State::Done->value] ?? 0,
                    'total' => array_sum($progress),
                    'byState' => $progress,
                ];
            }

            if ($node->unmetDependencies !== [] && ! $node->issue->hasState(State::Done)) {
                $waiting[] = ['id' => $node->issue->id, 'state' => $node->issue->state(), 'waitingFor' => $node->unmetDependencies];
            }
        }

        $summaries = [];

        foreach ([$epic->id, ...$descendants] as $id) {
            $summaries[$id] = $graph->find($id);
        }

        return [
            ...$this->source->card($epic),
            ...$this->source->claim($epic),
            'status' => $this->pipeline->statusOf($epic)?->toArray(),
            'progress' => $graph->progress($epic->id),
            'tree' => $this->node($tree),
            'stories' => $stories,
            'readyTasks' => array_map(fn (string $id): array => $this->source->card($graph->get($id)), $graph->readyTasks($epic->id)),
            'waiting' => $waiting,
            'dependents' => array_map(
                fn (array $dependent): array => [...$this->source->card($graph->get($dependent['id'])), 'via' => $dependent['via']],
                $graph->dependents($epic->id),
            ),
            'events' => array_map(
                fn (AgentComment $comment): array => $this->activity->event($comment, $summaries[$comment->issueId] ?? null),
                $this->source->recentAgentComments(self::EVENTS, [$epic->id, ...$descendants]),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws YouTrackException
     */
    private function node(TreeNode $node): array
    {
        return [
            ...$this->source->card($node->issue),
            ...$this->source->claim($node->issue),
            'ready' => $node->ready,
            'dependsOn' => $node->issue->dependencyIds(),
            'unmetDependencies' => $node->unmetDependencies,
            'children' => array_map($this->node(...), $node->children),
        ];
    }
}
