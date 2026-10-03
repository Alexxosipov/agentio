<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Obrazmisli\Agentio\YouTrack\Issue;

/**
 * An issue of an epic tree (EPIC -> STORY -> TASK) with its readiness.
 */
final readonly class TreeNode
{
    /**
     * @param  list<string>  $unmetDependencies
     * @param  list<TreeNode>  $children
     * @param  bool|null  $ready  Readiness of a Task or an Epic; null for other types
     */
    public function __construct(
        public Issue $issue,
        public int $depth,
        public array $unmetDependencies,
        public ?bool $ready,
        public array $children = [],
    ) {}

    /**
     * The node and all its descendants, depth first.
     *
     * @return list<TreeNode>
     */
    public function flatten(): array
    {
        $nodes = [$this];

        foreach ($this->children as $child) {
            array_push($nodes, ...$child->flatten());
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->issue->toArray(),
            'depth' => $this->depth,
            'parent' => $this->issue->parentId(),
            'dependsOn' => $this->issue->dependencyIds(),
            'unmetDependencies' => $this->unmetDependencies,
            'claimed' => $this->issue->isClaimed(),
            'ready' => $this->ready,
            'children' => array_map(fn (TreeNode $child): array => $child->toArray(), $this->children),
        ];
    }
}
