<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\YouTrack\Comment;

/**
 * A YouTrack comment written by an agent: it starts with an [AGENT:<KIND>] marker.
 */
final readonly class AgentComment
{
    private const string MARKER = '/^\[AGENT:([A-Z_]+)\]/';

    /**
     * @param  string  $kind  The marker kind as written, e.g. "START" (see AgentCommentKind for the known ones)
     */
    public function __construct(
        public string $issueId,
        public string $kind,
        public string $text,
        public ?string $author = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * The agent comment behind a YouTrack comment, or null when it is not an agent comment.
     */
    public static function fromComment(Comment $comment): ?self
    {
        $text = ltrim($comment->text);

        if (preg_match(self::MARKER, $text, $match) !== 1) {
            return null;
        }

        return new self($comment->issueId, $match[1], $text, $comment->author, $comment->createdAt);
    }

    public function kind(): ?AgentCommentKind
    {
        return AgentCommentKind::tryFrom($this->kind);
    }

    public function is(AgentCommentKind $kind): bool
    {
        return $this->kind === $kind->value;
    }

    public function endsClaim(): bool
    {
        return $this->kind()?->endsClaim() ?? false;
    }

    /**
     * The text after the [AGENT:<KIND>] marker.
     */
    public function body(): string
    {
        return trim((string) preg_replace(self::MARKER, '', $this->text, 1));
    }

    /**
     * The value of a "name: value" line of the comment (backticks stripped), e.g. owner, branch, worktree.
     */
    public function field(string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':[ \t]*`?([^`\r\n]*?)`?[ \t]*$/mi', $this->text, $match) !== 1) {
            return null;
        }

        return $match[1] === '' ? null : $match[1];
    }

    /**
     * The claim owner of an [AGENT:START] comment (`owner: <host>:<worktree>[#suffix]`).
     */
    public function owner(): ?string
    {
        if (! $this->is(AgentCommentKind::Start)) {
            return null;
        }

        $owner = $this->field('owner');

        return $owner === null ? null : (preg_split('/\s/', $owner)[0] ?? null);
    }

    /**
     * @return array{issueId: string, kind: string, text: string, body: string, author: string|null, createdAt: string|null, owner: string|null}
     */
    public function toArray(): array
    {
        return [
            'issueId' => $this->issueId,
            'kind' => $this->kind,
            'text' => $this->text,
            'body' => $this->body(),
            'author' => $this->author,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'owner' => $this->owner(),
        ];
    }
}
