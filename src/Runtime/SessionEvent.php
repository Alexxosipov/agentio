<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;

/**
 * One meaningful event of a Claude Code stream-json session log.
 */
final readonly class SessionEvent
{
    /**
     * @param  int  $offset  Byte offset of the log line the event comes from
     * @param  string  $text  Human-readable summary: the agent text, the tool input summary, the result text, ...
     * @param  string|null  $detail  Extra detail of a tool call when $text is its description: the command or the file path
     * @param  string|null  $tool  Tool name of ToolUse / Subagent / PermissionDenied events
     * @param  string|null  $subagent  For a Subagent event the subagent type; for events inside a subagent its description
     * @param  bool  $inSubagent  Whether the event happened inside a subagent
     * @param  string|null  $status  TaskFinished status (completed, failed, ...) or Result subtype (success, error_max_turns, ...)
     */
    public function __construct(
        public SessionEventType $type,
        public int $offset,
        public string $text,
        public ?CarbonImmutable $time = null,
        public ?string $detail = null,
        public ?string $tool = null,
        public ?string $subagent = null,
        public bool $inSubagent = false,
        public ?string $sessionId = null,
        public ?string $model = null,
        public ?string $status = null,
        public ?float $costUsd = null,
        public ?int $durationMs = null,
        public ?int $turns = null,
        public bool $isError = false,
    ) {}

    public function withTime(?CarbonImmutable $time): self
    {
        return new self(
            $this->type, $this->offset, $this->text, $time, $this->detail, $this->tool, $this->subagent, $this->inSubagent,
            $this->sessionId, $this->model, $this->status, $this->costUsd, $this->durationMs, $this->turns, $this->isError,
        );
    }

    /**
     * @return array{type: string, offset: int, time: string|null, text: string, detail: string|null, tool: string|null, subagent: string|null, inSubagent: bool, sessionId: string|null, model: string|null, status: string|null, costUsd: float|null, durationMs: int|null, turns: int|null, isError: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'offset' => $this->offset,
            'time' => $this->time?->toIso8601String(),
            'text' => $this->text,
            'detail' => $this->detail,
            'tool' => $this->tool,
            'subagent' => $this->subagent,
            'inSubagent' => $this->inSubagent,
            'sessionId' => $this->sessionId,
            'model' => $this->model,
            'status' => $this->status,
            'costUsd' => $this->costUsd,
            'durationMs' => $this->durationMs,
            'turns' => $this->turns,
            'isError' => $this->isError,
        ];
    }
}
