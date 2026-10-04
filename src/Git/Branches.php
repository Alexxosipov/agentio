<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Git;

use InvalidArgumentException;

/**
 * The branch model of the cycle. The production branch (main) holds what runs in production, the development
 * branch (dev) what runs on the develop server; every epic is worked on in a branch named after its YouTrack
 * issue id (TP-12), started from the development branch and merged back into it. Agents never push to, switch
 * to or move the development and the production branch.
 *
 * Branches of earlier agentio versions (epic/TP-12-<slug>) are still found, so epics started before the
 * upgrade are resumed and accepted on their old branch.
 */
final class Branches
{
    /** A YouTrack issue id: the project short name, a dash and the number. */
    public const string ISSUE_ID = '/^[A-Z][A-Z0-9_]*-[0-9]+$/';

    /** The prefix of the epic branches of earlier agentio versions. */
    public const string LEGACY_PREFIX = 'epic/';

    /**
     * The branch of an issue: its id.
     *
     * @throws InvalidArgumentException When the id is not a YouTrack issue id
     */
    public static function forIssue(string $issueId): string
    {
        if (preg_match(self::ISSUE_ID, $issueId) !== 1) {
            throw new InvalidArgumentException("Not a YouTrack issue id: {$issueId}");
        }

        return $issueId;
    }

    /**
     * Whether agents may push or delete the branch: the branch of an issue (or an epic branch of an earlier version).
     */
    public static function isWorkBranch(string $name): bool
    {
        return preg_match(self::ISSUE_ID, $name) === 1
            || preg_match('#^'.preg_quote(self::LEGACY_PREFIX, '#').'[A-Z][A-Z0-9_]*-[0-9]+(-|$)#', $name) === 1;
    }

    /**
     * The existing local branch of an issue: the one named after it, else an epic branch of an earlier version;
     * null when the repository has neither (or the id is not an issue id).
     */
    public static function find(Git $git, string $issueId): ?string
    {
        if (preg_match(self::ISSUE_ID, $issueId) !== 1) {
            return null;
        }

        if ($git->branchExists($issueId)) {
            return $issueId;
        }

        return $git->lines('for-each-ref', '--format=%(refname:short)', 'refs/heads/'.self::LEGACY_PREFIX.$issueId.'-*')[0] ?? null;
    }

    /**
     * The branch of an issue to work on: the existing one, else the new one named after the issue.
     */
    public static function resolve(Git $git, string $issueId): string
    {
        return self::find($git, $issueId) ?? self::forIssue($issueId);
    }
}
