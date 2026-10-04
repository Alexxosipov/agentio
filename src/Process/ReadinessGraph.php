<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Closure;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Relation;
use Obrazmisli\Agentio\YouTrack\State;
use OutOfBoundsException;

/**
 * The issue graph of a project and the readiness rules of the youtrack-workflow skill
 * (php artisan agentio:yt computes them the same way for the loop and the agents):
 *
 * - dependencies are inherited from all ancestors (a TASK waits for what its STORY and EPIC depend on);
 * - a dependency is met when it is Done, or in Review inside the same epic (its code is on the epic branch);
 * - a TASK is ready when it is Ready, not claimed and has no unmet dependencies;
 * - an EPIC is ready when it is Ready, not claimed, has no unmet dependencies and at least one ready TASK.
 */
final class ReadinessGraph
{
    /** @var array<string, Issue|null> */
    private array $issues = [];

    /**
     * @param  iterable<Issue>  $issues
     * @param  (Closure(string): ?Issue)|null  $resolver  Loads issues that are referenced but not in the list (e.g. from other projects)
     */
    public function __construct(iterable $issues, private readonly ?Closure $resolver = null)
    {
        foreach ($issues as $issue) {
            $this->issues[$issue->id] = $issue;
        }
    }

    /**
     * Every known issue, keyed by id.
     *
     * @return array<string, Issue>
     */
    public function issues(): array
    {
        return array_filter($this->issues, fn (?Issue $issue): bool => $issue !== null);
    }

    /**
     * @return list<Issue>
     */
    public function ofType(IssueType $type): array
    {
        return array_values(array_filter($this->issues(), fn (Issue $issue): bool => $issue->hasType($type)));
    }

    public function find(string $id): ?Issue
    {
        if (! array_key_exists($id, $this->issues)) {
            $this->issues[$id] = $this->resolver === null ? null : ($this->resolver)($id);
        }

        return $this->issues[$id];
    }

    public function get(string $id): Issue
    {
        return $this->find($id) ?? throw new OutOfBoundsException("Issue {$id} is not in the graph.");
    }

    public function parentOf(string $id): ?string
    {
        return $this->find($id)?->parentId();
    }

    /**
     * Parent, grandparent, ... (cycle-safe).
     *
     * @return list<string>
     */
    public function ancestors(string $id): array
    {
        $chain = [];

        for ($parent = $this->parentOf($id); $parent !== null && $parent !== $id && ! in_array($parent, $chain, true); $parent = $this->parentOf($parent)) {
            $chain[] = $parent;
        }

        return $chain;
    }

    /**
     * The epic the issue belongs to (the issue itself when it is an epic).
     */
    public function epicOf(string $id): ?string
    {
        foreach ([$id, ...$this->ancestors($id)] as $candidate) {
            if ($this->find($candidate)?->hasType(IssueType::Epic) === true) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function children(string $id): array
    {
        return $this->find($id)?->childIds() ?? [];
    }

    /**
     * Children, grandchildren, ... breadth first.
     *
     * @return list<string>
     */
    public function descendants(string $id): array
    {
        $result = [];
        $queue = $this->children($id);

        while ($queue !== []) {
            $child = array_shift($queue);

            if ($child === $id || in_array($child, $result, true)) {
                continue;
            }

            $result[] = $child;
            array_push($queue, ...$this->children($child));
        }

        return $result;
    }

    /**
     * Dependencies that still block the issue: its own and the ones inherited from its ancestors.
     * A dependency in Review counts as met when it lives in the same epic. Unknown issues are unmet.
     *
     * @return list<string>
     */
    public function unmetDependencies(string $id): array
    {
        $epic = $this->epicOf($id);
        $unmet = [];

        foreach ([$id, ...$this->ancestors($id)] as $holder) {
            foreach ($this->find($holder)?->related(Relation::DependsOn) ?? [] as $dependency) {
                $issue = $this->find($dependency);
                $sameEpic = $epic !== null && $this->epicOf($dependency) === $epic;

                if ($issue?->hasState(State::Done) === true || ($sameEpic && $issue?->hasState(State::Review) === true)) {
                    continue;
                }

                $unmet[] = $dependency;
            }
        }

        return array_values(array_unique($unmet));
    }

    public function isTaskReady(string $id): bool
    {
        $task = $this->find($id);

        return $task !== null
            && $task->hasType(IssueType::Task)
            && $task->hasState(State::Ready)
            && ! $task->isClaimed()
            && $this->unmetDependencies($id) === [];
    }

    /**
     * Tasks of the epic that can be started now.
     *
     * @return list<string>
     */
    public function readyTasks(string $epicId): array
    {
        return array_values(array_filter($this->descendants($epicId), $this->isTaskReady(...)));
    }

    public function isEpicReady(string $id): bool
    {
        $epic = $this->find($id);

        return $epic !== null
            && $epic->hasType(IssueType::Epic)
            && $epic->hasState(State::Ready)
            && ! $epic->isClaimed()
            && $this->unmetDependencies($id) === []
            && $this->readyTasks($id) !== [];
    }

    /**
     * Epics the loop would start now.
     *
     * @return list<string>
     */
    public function readyEpics(): array
    {
        return array_values(array_filter(
            array_map(fn (Issue $epic): string => $epic->id, $this->ofType(IssueType::Epic)),
            $this->isEpicReady(...),
        ));
    }

    /**
     * Dependency ("depends on") cycles, each as a path that ends with its first issue.
     *
     * @return list<list<string>>
     */
    public function dependencyCycles(): array
    {
        $cycles = [];
        $visiting = [];
        $done = [];

        $visit = function (string $id, array $path) use (&$visit, &$cycles, &$visiting, &$done): void {
            /** @var list<string> $path */
            if (isset($done[$id])) {
                return;
            }

            if (isset($visiting[$id])) {
                $cycles[] = [...array_slice($path, (int) array_search($id, $path, true)), $id];

                return;
            }

            $visiting[$id] = true;

            foreach ($this->find($id)?->related(Relation::DependsOn) ?? [] as $dependency) {
                $visit($dependency, [...$path, $id]);
            }

            unset($visiting[$id]);
            $done[$id] = true;
        };

        foreach (array_keys($this->issues()) as $id) {
            $visit($id, []);
        }

        return $cycles;
    }

    /**
     * The tree under an issue (usually an epic) with readiness of every node.
     */
    public function tree(string $rootId): TreeNode
    {
        return $this->node($rootId, 0, [$rootId]);
    }

    /**
     * Number of tasks under the issue by Stage (tasks without a Stage are counted under "").
     *
     * @return array<string, int>
     */
    public function progress(string $rootId): array
    {
        $counts = [];

        foreach ($this->descendants($rootId) as $id) {
            $issue = $this->find($id);

            if ($issue?->hasType(IssueType::Task) === true) {
                $state = $issue->state() ?? '';
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $path
     */
    private function node(string $id, int $depth, array $path): TreeNode
    {
        $issue = $this->get($id);
        $children = [];

        foreach ($issue->childIds() as $childId) {
            if (! in_array($childId, $path, true) && $this->find($childId) !== null) {
                $children[] = $this->node($childId, $depth + 1, [...$path, $childId]);
            }
        }

        $ready = match (true) {
            $issue->hasType(IssueType::Task) => $this->isTaskReady($id),
            $issue->hasType(IssueType::Epic) => $this->isEpicReady($id),
            default => null,
        };

        return new TreeNode($issue, $depth, $this->unmetDependencies($id), $ready, $children);
    }
}
