<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

/**
 * What happens to an epic branch in Review (AGENTIO_MERGE_POLICY, or the policy agentio:install recorded in
 * .agentio.json; local-branch by default).
 */
enum MergePolicy: string
{
    /** The epic branch stays local; a human merges it. */
    case LocalBranch = 'local-branch';

    /** The epic branch is pushed and a pull request is opened; a human merges it. */
    case PullRequest = 'pull-request';

    /** The epic branch is merged automatically after a green full test run. */
    case AutoMerge = 'auto-merge';
}
