<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

enum Tag: string
{
    /** An agent holds the issue (see the [AGENT:START] comment for the owner). */
    case Claimed = 'agent-claimed';

    /** A raw idea waiting for planning. */
    case Idea = 'idea';

    /**
     * An idea for later: planning stops after the system analysis (written into the «Идеи» knowledge base
     * article), the idea goes to On Hold and nothing goes to development until a human takes it.
     */
    case Parked = 'parked';
}
