<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

enum FileStatus: string
{
    case Created = 'created';

    case Updated = 'updated';

    /** The file already has the expected content. */
    case Unchanged = 'unchanged';

    /** The file was edited by hand and differs from the stub: kept (use --force to overwrite). */
    case Skipped = 'skipped';
}
