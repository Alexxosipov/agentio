<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Obrazmisli\Agentio\YouTrack\Comment;
use Traversable;

/**
 * The agent comments of an issue, oldest first.
 *
 * @implements IteratorAggregate<int, AgentComment>
 */
final readonly class AgentComments implements Countable, IteratorAggregate
{
    /**
     * @param  list<AgentComment>  $comments
     */
    public function __construct(private array $comments = []) {}

    /**
     * @param  iterable<Comment>  $comments  Comments in creation order
     */
    public static function fromComments(iterable $comments): self
    {
        $agentComments = [];

        foreach ($comments as $comment) {
            $agentComment = AgentComment::fromComment($comment);

            if ($agentComment !== null) {
                $agentComments[] = $agentComment;
            }
        }

        return new self($agentComments);
    }

    /**
     * @return list<AgentComment>
     */
    public function all(): array
    {
        return $this->comments;
    }

    /**
     * @return list<AgentComment>
     */
    public function ofKind(AgentCommentKind $kind): array
    {
        return array_values(array_filter($this->comments, fn (AgentComment $comment): bool => $comment->is($kind)));
    }

    public function last(?AgentCommentKind $kind = null): ?AgentComment
    {
        $comments = $kind === null ? $this->comments : $this->ofKind($kind);

        return $comments === [] ? null : $comments[count($comments) - 1];
    }

    /**
     * The [AGENT:START] comment of the active claim: the earliest START with an owner
     * posted after the last DONE / BLOCKED / RELEASE (the rule of agentio:yt claim).
     */
    public function activeClaim(): ?AgentComment
    {
        $claim = null;

        foreach ($this->comments as $comment) {
            if ($comment->endsClaim()) {
                $claim = null;
            } elseif ($claim === null && $comment->owner() !== null) {
                $claim = $comment;
            }
        }

        return $claim;
    }

    /**
     * The owner of the active claim, or null when the issue is not claimed.
     */
    public function claimOwner(): ?string
    {
        return $this->activeClaim()?->owner();
    }

    public function count(): int
    {
        return count($this->comments);
    }

    /**
     * @return Traversable<int, AgentComment>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->comments);
    }
}
