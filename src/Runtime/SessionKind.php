<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

enum SessionKind: string
{
    /** /agentio-work-epic <ID>: log <ID>.log, pid <ID>.pid */
    case Epic = 'epic';

    /** /agentio-plan <ID>: log plan-<ID>.log */
    case Plan = 'plan';

    public static function fromName(string $name): self
    {
        return str_starts_with($name, 'plan-') ? self::Plan : self::Epic;
    }
}
