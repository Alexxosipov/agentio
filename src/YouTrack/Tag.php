<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

enum Tag: string
{
    /** An agent holds the issue (see the [AGENT:START] comment for the owner). */
    case Claimed = 'agent-claimed';

    /** A raw idea waiting for planning. */
    case Idea = 'idea';
}
