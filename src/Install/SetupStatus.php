<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

enum SetupStatus: string
{
    /** Already configured: nothing to do. */
    case Exists = 'exists';

    /** Created (or, in a dry run, would be created). */
    case Create = 'create';

    /** Existing entity extended, e.g. values added to a bundle. */
    case Update = 'update';

    /** Exists but differs from what agentio expects; left as is. */
    case Warning = 'warning';

    /** Missing or set up otherwise than the cycle requires, and not fixed by the setup: the human has to. */
    case Error = 'error';
}
