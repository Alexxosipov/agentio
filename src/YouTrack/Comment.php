<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use Carbon\CarbonImmutable;

/**
 * An issue comment normalised from the YouTrack REST representation (see Client::COMMENT_FIELDS).
 */
final readonly class Comment
{
    /**
     * @param  list<string>  $issueTags  The tags of the issue, when the payload names them
     */
    public function __construct(
        public string $id,
        public string $issueId,
        public string $text,
        public ?string $author = null,
        public ?string $authorName = null,
        public ?CarbonImmutable $createdAt = null,
        public ?string $issueSummary = null,
        public array $issueTags = [],
    ) {}

    /**
     * Build a comment; the issue is taken from the payload ("issue": {"idReadable"}) when present.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromApi(array $raw, string $issueId = ''): self
    {
        $author = is_array($raw['author'] ?? null) ? $raw['author'] : [];
        $issue = is_array($raw['issue'] ?? null) ? $raw['issue'] : [];
        $created = $raw['created'] ?? null;

        return new self(
            id: is_scalar($raw['id'] ?? null) ? (string) $raw['id'] : '',
            issueId: is_string($issue['idReadable'] ?? null) ? $issue['idReadable'] : $issueId,
            text: is_string($raw['text'] ?? null) ? $raw['text'] : '',
            author: is_string($author['login'] ?? null) ? $author['login'] : null,
            authorName: is_string($author['fullName'] ?? null) ? $author['fullName'] : null,
            createdAt: is_int($created) ? CarbonImmutable::createFromTimestampMsUTC($created) : null,
            issueSummary: is_string($issue['summary'] ?? null) ? $issue['summary'] : null,
            issueTags: array_values(array_filter(array_map(
                fn (mixed $tag): ?string => is_array($tag) && is_string($tag['name'] ?? null) ? $tag['name'] : null,
                is_array($issue['tags'] ?? null) ? $issue['tags'] : [],
            ))),
        );
    }

    /**
     * A comment from the get_issue_comments tool of the YouTrack MCP server: {author, text, url, createdAt (ms)}.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromMcp(array $raw, string $issueId): self
    {
        $created = $raw['createdAt'] ?? null;
        $url = is_string($raw['url'] ?? null) ? $raw['url'] : '';

        return new self(
            id: preg_match('/focus=Comments-([\w.-]+)/', $url, $match) === 1 ? $match[1] : '',
            issueId: $issueId,
            text: is_string($raw['text'] ?? null) ? $raw['text'] : '',
            author: is_string($raw['author'] ?? null) ? $raw['author'] : null,
            createdAt: is_int($created) ? CarbonImmutable::createFromTimestampMsUTC($created) : null,
        );
    }

    /**
     * @return array{id: string, issueId: string, issueSummary: string|null, text: string, author: string|null, authorName: string|null, createdAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'issueSummary' => $this->issueSummary,
            'text' => $this->text,
            'author' => $this->author,
            'authorName' => $this->authorName,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
