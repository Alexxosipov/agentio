<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\PipelineStage;
use Obrazmisli\Agentio\Process\PipelineStageResolver;
use Obrazmisli\Agentio\Process\PipelineStatus;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The pipeline view: every idea with the epics it led to, and the epics without an idea, each with its stage.
 */
final readonly class PipelinePresenter
{
    public function __construct(private YouTrackSource $source) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $items = $this->source->attempt($this->items(...), []);

        return [
            'youtrack' => $this->source->health(),
            'stages' => PipelineStage::toList(),
            'items' => $items,
        ];
    }

    /**
     * The stage of an idea or an epic, or null for other issues.
     *
     * @throws YouTrackException
     */
    public function statusOf(Issue $issue): ?PipelineStatus
    {
        $resolver = new PipelineStageResolver($this->source->graph());

        if ($issue->hasType(IssueType::Epic)) {
            return $resolver->epic($issue, $this->comments($issue));
        }

        if ($issue->isIdea()) {
            return $resolver->idea($issue, $this->comments($issue), array_map(
                fn (string $epic): PipelineStatus => $resolver->epic($this->source->graph()->get($epic), $this->comments($this->source->graph()->get($epic))),
                $resolver->epicsOf($issue),
            ));
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws YouTrackException
     */
    private function items(): array
    {
        $graph = $this->source->graph();
        $resolver = new PipelineStageResolver($graph);
        $epics = [];

        foreach ($graph->ofType(IssueType::Epic) as $epic) {
            $epics[$epic->id] = $resolver->epic($epic, $this->comments($epic));
        }

        $items = [];
        $linked = [];

        foreach ($graph->issues() as $issue) {
            if (! $issue->isIdea() || $issue->hasType(IssueType::Epic)) {
                continue;
            }

            $ideaEpics = $resolver->epicsOf($issue);
            $statuses = array_values(array_intersect_key($epics, array_flip($ideaEpics)));
            array_push($linked, ...$ideaEpics);

            $items[] = $this->item('idea', $issue, $resolver->idea($issue, $this->comments($issue), $statuses), array_map(
                fn (PipelineStatus $status): array => $this->item('epic', $graph->get($status->issueId), $status),
                $statuses,
            ));
        }

        foreach ($epics as $id => $status) {
            if (! in_array($id, $linked, true)) {
                $items[] = $this->item('epic', $graph->get($id), $status);
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['active'] ? 0 : 1, $b['updatedAt']] <=> [$b['active'] ? 0 : 1, $a['updatedAt']]);

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $epics
     * @return array{kind: string, issue: array<string, mixed>, status: array<string, mixed>, active: bool, updatedAt: string, epics: list<array<string, mixed>>}
     *
     * @throws YouTrackException
     */
    private function item(string $kind, Issue $issue, PipelineStatus $status, array $epics = []): array
    {
        return [
            'kind' => $kind,
            'issue' => [...$this->source->card($issue), ...$this->source->claim($issue)],
            'status' => $status->toArray(),
            'active' => $status->stage !== PipelineStage::Done && ! $status->onHold,
            'updatedAt' => (string) $issue->updatedAt?->toIso8601String(),
            'epics' => $epics,
        ];
    }

    /**
     * @throws YouTrackException
     */
    private function comments(Issue $issue): AgentComments
    {
        return PipelineStageResolver::needsComments($issue) ? $this->source->agentComments($issue->id) : new AgentComments;
    }
}
