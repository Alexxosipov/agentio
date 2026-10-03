<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;

/**
 * A Claude Code session started by the loop, as seen from its pid file.
 */
final readonly class Session
{
    public function __construct(
        public string $name,
        public string $issueId,
        public SessionKind $kind,
        public ?int $pid,
        public bool $alive,
        public string $logPath,
        public ?CarbonImmutable $startedAt = null,
        public int $restarts = 0,
    ) {}

    /**
     * @return array{name: string, issueId: string, kind: string, pid: int|null, alive: bool, logPath: string, startedAt: string|null, restarts: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'issueId' => $this->issueId,
            'kind' => $this->kind->value,
            'pid' => $this->pid,
            'alive' => $this->alive,
            'logPath' => $this->logPath,
            'startedAt' => $this->startedAt?->toIso8601String(),
            'restarts' => $this->restarts,
        ];
    }
}
