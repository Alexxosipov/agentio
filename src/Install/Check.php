<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * One precondition of the autonomous cycle and whether the machine meets it.
 */
final readonly class Check
{
    /**
     * @param  bool  $required  A failed required check stops the cycle; an optional one only limits it
     */
    public function __construct(
        public string $name,
        public bool $ok,
        public bool $required,
        public string $hint = '',
    ) {}
}
