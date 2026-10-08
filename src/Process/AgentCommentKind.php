<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

/**
 * The markers agents start their YouTrack comments with: [AGENT:START], [AGENT:DECISION], ...
 */
enum AgentCommentKind: string
{
    case Start = 'START';
    case Decision = 'DECISION';
    case Blocked = 'BLOCKED';
    case Done = 'DONE';
    case Release = 'RELEASE';

    /** The developer paused the epic (Stage On Hold); the claim stays with its owner. */
    case Pause = 'PAUSE';

    /** The developer lifted the pause of the epic. */
    case Resume = 'RESUME';

    /**
     * Whether a comment of this kind ends the active claim of an issue.
     */
    public function endsClaim(): bool
    {
        return in_array($this, [self::Done, self::Blocked, self::Release], true);
    }
}
