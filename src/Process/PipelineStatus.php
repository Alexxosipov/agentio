<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

/**
 * Where an idea or an epic is in the pipeline, as derived by PipelineStageResolver.
 */
final readonly class PipelineStatus
{
    /**
     * @param  array{done: int, total: int, inProgress: int, blocked: int}  $tasks  Tasks of the epic(s) by progress
     * @param  list<string>  $waitingFor  Unmet dependencies of a Ready epic
     * @param  bool  $onHold  A paused epic or a parked idea (Stage On Hold): nobody works on it until a human decides
     */
    public function __construct(
        public string $issueId,
        public PipelineStage $stage,
        public bool $blocked = false,
        public ?string $blockedReason = null,
        public array $tasks = ['done' => 0, 'total' => 0, 'inProgress' => 0, 'blocked' => 0],
        public ?string $note = null,
        public array $waitingFor = [],
        public bool $onHold = false,
    ) {}

    /**
     * @return array{issueId: string, stage: string, stageLabel: string, position: int, blocked: bool, blockedReason: string|null, tasks: array{done: int, total: int, inProgress: int, blocked: int}, note: string|null, waitingFor: list<string>, onHold: bool}
     */
    public function toArray(): array
    {
        return [
            'issueId' => $this->issueId,
            'stage' => $this->stage->value,
            'stageLabel' => $this->stage->label(),
            'position' => $this->stage->position(),
            'blocked' => $this->blocked,
            'blockedReason' => $this->blockedReason,
            'tasks' => $this->tasks,
            'note' => $this->note,
            'waitingFor' => $this->waitingFor,
            'onHold' => $this->onHold,
        ];
    }
}
