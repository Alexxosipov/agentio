<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;

/**
 * A line of loop.log: "[2026-10-03 14:57:38] TP-2: started /work-epic (...)".
 */
final readonly class LoopLogEntry
{
    public function __construct(
        public string $message,
        public ?CarbonImmutable $time = null,
        public ?string $issueId = null,
    ) {}

    /**
     * Parse a line; the loop writes local time, which is interpreted in the given timezone.
     */
    public static function parse(string $line, ?string $timezone = null): self
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (.*)$/s', $line, $match) !== 1) {
            return new self($line);
        }

        $time = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $match[1], $timezone);
        $issueId = preg_match('/^([A-Z][A-Z0-9_]*-\d+):/', $match[2], $issue) === 1 ? $issue[1] : null;

        return new self($match[2], $time instanceof CarbonImmutable ? $time : null, $issueId);
    }

    /**
     * @return array{time: string|null, message: string, issueId: string|null}
     */
    public function toArray(): array
    {
        return [
            'time' => $this->time?->toIso8601String(),
            'message' => $this->message,
            'issueId' => $this->issueId,
        ];
    }
}
