<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\GitHub;

/**
 * A pull request as `gh pr list --json` / `gh pr view --json` describes it (GitHub::FIELDS).
 */
final readonly class PullRequest
{
    public const string OPEN = 'OPEN';

    public const string MERGED = 'MERGED';

    public const string CONFLICTING = 'CONFLICTING';

    public const string MERGEABLE = 'MERGEABLE';

    public function __construct(
        public int $number,
        public string $url,
        public string $state,
        public string $title,
        public string $head,
        public string $base,
        public bool $draft = false,
        public ?string $mergeable = null,
        public ?string $mergeCommit = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromJson(array $data): ?self
    {
        if (! is_int($data['number'] ?? null) || ! is_string($data['url'] ?? null)) {
            return null;
        }

        $commit = is_array($data['mergeCommit'] ?? null) ? ($data['mergeCommit']['oid'] ?? null) : null;

        return new self(
            $data['number'],
            $data['url'],
            strtoupper(is_string($data['state'] ?? null) ? $data['state'] : ''),
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['headRefName'] ?? null) ? $data['headRefName'] : '',
            is_string($data['baseRefName'] ?? null) ? $data['baseRefName'] : '',
            ($data['isDraft'] ?? false) === true,
            is_string($data['mergeable'] ?? null) ? strtoupper($data['mergeable']) : null,
            is_string($commit) && $commit !== '' ? $commit : null,
        );
    }

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }

    public function isMerged(): bool
    {
        return $this->state === self::MERGED;
    }

    /**
     * Whether GitHub can merge it: true, false (conflicts with the base), or null while GitHub has not computed it.
     */
    public function canMerge(): ?bool
    {
        return match ($this->mergeable) {
            self::MERGEABLE => true,
            self::CONFLICTING => false,
            default => null,
        };
    }

    /**
     * "#12".
     */
    public function label(): string
    {
        return '#'.$this->number;
    }

    /**
     * @return array{number: int, url: string, state: string, title: string, head: string, base: string, draft: bool, mergeable: string|null, mergeCommit: string|null}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'url' => $this->url,
            'state' => $this->state,
            'title' => $this->title,
            'head' => $this->head,
            'base' => $this->base,
            'draft' => $this->draft,
            'mergeable' => $this->mergeable,
            'mergeCommit' => $this->mergeCommit,
        ];
    }
}
