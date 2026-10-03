<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

enum MergePolicy: string
{
    /** The epic branch stays local; a human merges it. */
    case LocalBranch = 'local-branch';

    /** The epic branch is pushed and a pull request is opened; a human merges it. */
    case PullRequest = 'pull-request';

    /** The epic branch is merged automatically after a green full test run. */
    case AutoMerge = 'auto-merge';

    /**
     * Resolve the policy the same way scripts/agent-loop.sh does: the configured value first,
     * then the `MERGE_POLICY:` line of CLAUDE.md, then local-branch.
     */
    public static function resolve(?string $configured, ?string $claudeMarkdownPath = null): self
    {
        if ($configured !== null && $configured !== '') {
            return self::from($configured);
        }

        if ($claudeMarkdownPath !== null && is_file($claudeMarkdownPath)) {
            return self::fromClaudeMarkdown((string) file_get_contents($claudeMarkdownPath)) ?? self::LocalBranch;
        }

        return self::LocalBranch;
    }

    /**
     * The policy declared by the first `MERGE_POLICY:` line of a CLAUDE.md document.
     */
    public static function fromClaudeMarkdown(string $markdown): ?self
    {
        if (preg_match('/^MERGE_POLICY:[ \t]*`?([a-z-]*)/m', $markdown, $match) !== 1) {
            return null;
        }

        return self::tryFrom($match[1]);
    }
}
