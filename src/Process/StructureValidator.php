<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;

/**
 * The structure rules of planned work (agentio:yt validate): every issue under an epic has the summary prefix
 * of its Type and a parent of the right type, every story has tasks, a Ready epic has a first wave of ready
 * tasks (counting only the dependencies inside the epic: an epic that waits for another epic is planned right),
 * and no dependency forms a cycle.
 */
final readonly class StructureValidator
{
    public function __construct(private ReadinessGraph $graph) {}

    /**
     * What is wrong with the epics and everything under them; empty when nothing is.
     *
     * @param  list<string>  $epics
     * @return list<string>
     */
    public function problems(array $epics): array
    {
        $problems = [];

        foreach ($epics as $epic) {
            foreach ([$epic, ...$this->graph->descendants($epic)] as $id) {
                $node = $this->graph->get($id);
                $type = IssueType::tryFrom((string) $node->type());

                if ($type === null || $type === IssueType::Idea || ! str_starts_with($node->summary, $type->prefix())) {
                    $problems[] = "{$id}: Type '{$node->type()}' does not match the summary prefix.";
                }

                $expected = $type?->parentType();
                $parent = $node->parentId();

                if ($expected !== null && ($parent === null || $this->graph->find($parent)?->type() !== $expected->value)) {
                    $problems[] = "{$id}: a {$type->value} must be a subtask of a {$expected->value}.";
                }

                if ($type === IssueType::Story && $node->childIds() === []) {
                    $problems[] = "{$id}: the story has no tasks.";
                }
            }

            if ($this->graph->get($epic)->hasState(State::Ready) && $this->graph->firstWave($epic) === []) {
                $problems[] = "{$epic}: the epic is Ready but its first wave of ready tasks is empty: every task waits for a task of the epic that is not Ready or not described.";
            }
        }

        foreach ($this->graph->dependencyCycles() as $cycle) {
            $problems[] = 'Dependency cycle: '.implode(' -> ', $cycle);
        }

        return $problems;
    }
}
