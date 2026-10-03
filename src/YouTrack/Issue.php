<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use Carbon\CarbonImmutable;

/**
 * An issue normalised from the YouTrack REST representation (see Client::ISSUE_FIELDS).
 */
final readonly class Issue
{
    /**
     * @param  array<string, string|null>  $fields  Custom field name => value (names, logins or presentations; multi-values joined with ", ")
     * @param  array<string, string>  $tags  Tag name => tag id
     * @param  array<string, list<string>>  $relations  Relation name (e.g. "depends on") => linked issue ids
     */
    public function __construct(
        public string $id,
        public string $summary,
        public array $fields = [],
        public array $tags = [],
        public array $relations = [],
        public ?string $description = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?CarbonImmutable $resolvedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromApi(array $raw): self
    {
        $fields = [];

        foreach (self::listOf($raw['customFields'] ?? null) as $field) {
            if (is_string($field['name'] ?? null)) {
                $fields[$field['name']] = self::fieldValue($field['value'] ?? null);
            }
        }

        $relations = [];

        foreach (self::listOf($raw['links'] ?? null) as $link) {
            $type = is_array($link['linkType'] ?? null) ? $link['linkType'] : [];
            $relation = ($link['direction'] ?? null) === 'INWARD' ? ($type['targetToSource'] ?? '') : ($type['sourceToTarget'] ?? '');

            foreach (self::listOf($link['issues'] ?? null) as $linked) {
                if (is_string($relation) && $relation !== '' && is_string($linked['idReadable'] ?? null)) {
                    $relations[$relation][] = $linked['idReadable'];
                }
            }
        }

        $tags = [];

        foreach (self::listOf($raw['tags'] ?? null) as $tag) {
            if (is_string($tag['name'] ?? null)) {
                $tags[$tag['name']] = is_scalar($tag['id'] ?? null) ? (string) $tag['id'] : '';
            }
        }

        return new self(
            id: is_string($raw['idReadable'] ?? null) ? $raw['idReadable'] : '',
            summary: is_string($raw['summary'] ?? null) ? $raw['summary'] : '',
            fields: $fields,
            tags: $tags,
            relations: $relations,
            description: is_string($raw['description'] ?? null) ? $raw['description'] : null,
            createdAt: self::timestamp($raw['created'] ?? null),
            updatedAt: self::timestamp($raw['updated'] ?? null),
            resolvedAt: self::timestamp($raw['resolved'] ?? null),
        );
    }

    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    public function state(): ?string
    {
        return $this->field('State');
    }

    public function type(): ?string
    {
        return $this->field('Type');
    }

    public function stage(): ?string
    {
        return $this->field('Stage');
    }

    public function hasState(State ...$states): bool
    {
        return in_array($this->state(), array_map(fn (State $state): string => $state->value, $states), true);
    }

    public function hasType(IssueType ...$types): bool
    {
        return in_array($this->type(), array_map(fn (IssueType $type): string => $type->value, $types), true);
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return array_keys($this->tags);
    }

    public function hasTag(Tag|string $tag): bool
    {
        return array_key_exists($tag instanceof Tag ? $tag->value : $tag, $this->tags);
    }

    public function isClaimed(): bool
    {
        return $this->hasTag(Tag::Claimed);
    }

    /**
     * An idea is an issue of Type Idea or one tagged "idea".
     */
    public function isIdea(): bool
    {
        return $this->hasType(IssueType::Idea) || $this->hasTag(Tag::Idea);
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    /**
     * @return list<string>
     */
    public function related(Relation|string $relation): array
    {
        return $this->relations[$relation instanceof Relation ? $relation->value : $relation] ?? [];
    }

    public function parentId(): ?string
    {
        return $this->related(Relation::SubtaskOf)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function childIds(): array
    {
        return $this->related(Relation::ParentFor);
    }

    /**
     * @return list<string>
     */
    public function dependencyIds(): array
    {
        return $this->related(Relation::DependsOn);
    }

    /**
     * @return array{id: string, summary: string, state: string|null, type: string|null, stage: string|null, fields: array<string, string|null>, tags: list<string>, relations: array<string, list<string>>, description: string|null, createdAt: string|null, updatedAt: string|null, resolvedAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->summary,
            'state' => $this->state(),
            'type' => $this->type(),
            'stage' => $this->stage(),
            'fields' => $this->fields,
            'tags' => $this->tagNames(),
            'relations' => $this->relations,
            'description' => $this->description,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
            'resolvedAt' => $this->resolvedAt?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function fieldValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            $values = array_filter(array_map(self::fieldValue(...), $value), fn (?string $item): bool => $item !== null);

            return $values === [] ? null : implode(', ', $values);
        }

        foreach (['name', 'login', 'presentation', 'text'] as $key) {
            if (is_scalar($value[$key] ?? null)) {
                return (string) $value[$key];
            }
        }

        return null;
    }

    private static function timestamp(mixed $milliseconds): ?CarbonImmutable
    {
        return is_int($milliseconds) || (is_string($milliseconds) && ctype_digit($milliseconds))
            ? CarbonImmutable::createFromTimestampMsUTC((int) $milliseconds)
            : null;
    }
}
