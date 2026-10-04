<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Throwable;

/**
 * Who works on an issue. A claim is an [AGENT:START] comment with the owner (who holds it, see AgentComments)
 * plus the agent-claimed tag and Stage In Progress (what the readiness rules and the searches see). claim() and
 * release() keep the two in step: a claim that fails half-way is withdrawn, and a release always ends the
 * claim in the comments, also when the agent forgot its [AGENT:DONE] / [AGENT:RELEASE].
 *
 * Owners are "<host>:<worktree>[#<as>]": an epic orchestrator owns its epic as "<host>:<worktrees>/<EPIC>", a
 * developer its task as "…#<TASK>", the project manager an idea as "<host>:<main checkout>#pm".
 */
final readonly class Claims
{
    /** The owner suffix of the project manager planning an idea. */
    public const string PLANNER = 'pm';

    public function __construct(private Tools $tools) {}

    public static function owner(string $worktree, ?string $as = null, ?string $host = null): string
    {
        return ($host ?? (string) gethostname()).':'.rtrim($worktree, '/').($as === null || $as === '' ? '' : '#'.$as);
    }

    /**
     * The owner of the active claim of the issue, or null when nobody holds it.
     *
     * @throws YouTrackException
     */
    public function ownerOf(string $id): ?string
    {
        return AgentComments::fromComments($this->tools->comments($id))->claimOwner();
    }

    /**
     * Claim the issue, or resume an own claim; then read the comments again and check that the active claim is
     * ours (two agents racing for one issue: the earlier [AGENT:START] wins).
     *
     * @throws YouTrackException
     */
    public function claim(string $id, string $owner, string $branch, string $worktree, ?string $plan = null): ClaimResult
    {
        $current = $this->ownerOf($id);

        if ($current !== null && $current !== $owner) {
            return ClaimResult::lost($id, $current);
        }

        if ($current === null) {
            $this->tools->addComment($id, "[AGENT:START]\nowner: `{$owner}`\nbranch: `{$branch}`\nworktree: `{$worktree}`\n\n".($plan ?? 'The plan follows in the next [AGENT:START] comment.'));
        }

        try {
            $this->tools->addTag($id, Tag::Claimed->value);
            $this->tools->updateFields($id, [State::FIELD => State::InProgress->value]);
        } catch (YouTrackException $exception) {
            // A START comment without the tag would hold the issue for nobody: withdraw what was written.
            if ($current === null) {
                $this->withdraw($id, $exception);
            }

            throw $exception;
        }

        $winner = $this->ownerOf($id);

        return $winner === $owner ? ClaimResult::claimed($id, $owner, resumed: $current !== null) : ClaimResult::lost($id, $winner);
    }

    /**
     * Post the comment (when given), make sure the claim is ended in the comments ([AGENT:RELEASE] when nothing
     * ended it), set the Stage and remove the agent-claimed tag. Safe to run again after a failure.
     *
     * @throws YouTrackException
     */
    public function release(string $id, State $state, ?string $comment = null): void
    {
        if ($comment !== null) {
            $this->tools->addComment($id, $comment);
        }

        $owner = $this->ownerOf($id);

        if ($owner !== null) {
            $this->tools->addComment($id, "[AGENT:RELEASE]\nowner: `{$owner}` → {$state->value}");
        }

        $this->tools->updateFields($id, [State::FIELD => $state->value]);

        if (Issue::fromMcp($this->tools->issue($id))->isClaimed()) {
            $this->tools->removeTag($id, Tag::Claimed->value);
        }
    }

    private function withdraw(string $id, Throwable $reason): void
    {
        try {
            $this->tools->addComment($id, "[AGENT:RELEASE]\nThe claim failed: ".$reason->getMessage());
        } catch (Throwable) {
            // YouTrack is failing anyway; the caller reports the original error.
        }
    }
}
