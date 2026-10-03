<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

enum LoopStatus: string
{
    case Running = 'running';

    /** The loop is running but the stop flag is set: it exits after the current step. */
    case Stopping = 'stopping';

    case Stopped = 'stopped';
}
