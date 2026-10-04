<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

/**
 * The outcome of Claims::claim(): claimed (or resumed) by the owner, or lost to the owner that holds the issue
 * (null when the claim was released in the meantime).
 */
final readonly class ClaimResult
{
    private function __construct(
        public string $id,
        public bool $won,
        public ?string $owner,
        public bool $resumed = false,
    ) {}

    public static function claimed(string $id, string $owner, bool $resumed): self
    {
        return new self($id, true, $owner, $resumed);
    }

    public static function lost(string $id, ?string $owner): self
    {
        return new self($id, false, $owner);
    }
}
