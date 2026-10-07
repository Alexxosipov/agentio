<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\GitHub;

use RuntimeException;

/**
 * The GitHub CLI is missing, not logged in, or refused a command (the message is gh's own error).
 */
final class GitHubException extends RuntimeException
{
    public static function notInstalled(): self
    {
        return new self('GitHub CLI (gh) is not installed: install it (https://cli.github.com) and run gh auth login.');
    }

    public static function notAuthenticated(): self
    {
        return new self('GitHub CLI (gh) is not logged in: run gh auth login.');
    }
}
