<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

final readonly class StageMap
{
    /**
     * @param  array<string, string>  $map  State => Stage
     */
    public function __construct(private array $map) {}

    /**
     * The Stage derived from the given State, or null when the State is unknown.
     */
    public function stageFor(?string $state): ?string
    {
        return $state === null ? null : ($this->map[$state] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->map;
    }
}
